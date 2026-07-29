<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\{
    Assets,
    AssetDeprPolicy,
    AssetDeprMovement,
    AssetDeprMonthly,
    AssetDeprYearly,
    AssetDeprTransferRequest,
    MasterStatus
};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Models\AssetDeprMonthClosing;
use App\Models\AssetDeprYearClosing;

class DepreciationController extends Controller
{
    private const TRANSFER_TYPE_CARRY_OVER = 'carry_over_gross_accum';
    private const TRANSFER_TYPE_ACQ_FIX    = 'acq_fix';
    private const STATUS_APR = 'APR';
    private const STATUS_ACC = 'ACC';
    private const STATUS_REJ = 'REJ';
    /**
     * Guard semua operasi yang menulis ledger untuk suatu period.
     *
     * @param Carbon $period   startOfMonth
     * @param string $context  e.g. 'runMonth', 'adjDepr', 'approveTransfer'
     * @param array $opt       options:
     *   - require_prev_month: bool (default true)
     *   - enforce_first_run_min_eligible: bool (default true)
     *   - allow_when_no_closing: bool (default false) -> jika true, skip NO_CLOSING_YET check (jarang dipakai)
     *
     * @return array { year:int, period:string }
     * @throws \RuntimeException with coded message:
     *   YEAR_LOCKED|{year}|{context}
     *   PREV_YEAR_NOT_BUILT|{prev}|{year}|{context}
     *   PREV_MONTH_NOT_PROCESSED|{needPeriod}
     *   FIRST_RUN_MUST_START_AT_MIN_ELIGIBLE|{needPeriod}
     *   NO_CLOSING_YET|{context}
     */
    private function latestProcessedDepreciationPeriod(): ?Carbon
    {
        $latest = AssetDeprMonthClosing::query()
            ->max('period');

        return $latest
            ? Carbon::parse($latest)->startOfMonth()
            : null;
    }
    private function guardWritablePeriod(Carbon $period, string $context, array $opt = []): array
    {
        $period = $period->copy()->startOfMonth();
        $year   = (int) $period->year;

        $requirePrevMonth   = (bool) ($opt['require_prev_month'] ?? true);
        $enforceFirstRunMin = (bool) ($opt['enforce_first_run_min_eligible'] ?? true);

        // 1) year locked
        if ($this->isYearLocked($year)) {
            throw new \RuntimeException("YEAR_LOCKED|{$year}|{$context}");
        }

        // 2) chain gate prev-year (skip kalau prev-year kosong)
        $this->assertPrevYearBuiltUnlessEmpty($year, $context);

        // 3) prev-month gate / first-run gate
        $hasAnyClosing = AssetDeprMonthClosing::query()->limit(1)->exists();

        if (! $hasAnyClosing) {
            if ($enforceFirstRunMin) {
                $minEligible = DB::table('assets as a')
                    ->join('assets_value as av', 'av.asset_uuid', '=', 'a.uuid')
                    ->leftJoin('assets_depr_policy as p', 'p.asset_uuid', '=', 'a.uuid')
                    ->where('a.kode_status', '!=', 'DIS')
                    ->whereNotNull('av.capitalization_date')
                    ->selectRaw("
                    MIN(
                        CASE
                            WHEN EXTRACT(DAY FROM av.capitalization_date) <= COALESCE(p.cutoff_day, 15)
                                THEN (date_trunc('month', av.capitalization_date) + INTERVAL '0 month')::date
                            ELSE (date_trunc('month', av.capitalization_date) + INTERVAL '1 month')::date
                        END
                    ) as min_eligible
                ")
                    ->value('min_eligible');

                if ($minEligible) {
                    $first = Carbon::parse($minEligible)->startOfMonth()->toDateString();
                    if ($period->toDateString() !== $first) {
                        throw new \RuntimeException("FIRST_RUN_MUST_START_AT_MIN_ELIGIBLE|{$first}");
                    }
                }
            }

            return ['year' => $year, 'period' => $period->toDateString()];
        }

        if ($requirePrevMonth) {
            $this->ensurePrevMonthProcessed($period); // <-- sudah fixed (skip kalau prev-month kosong)
        }

        return ['year' => $year, 'period' => $period->toDateString()];
    }
    private function reprocessDepreciationFrom(Carbon $start): void
    {
        $start = $start->copy()->startOfMonth();

        $latest = $this->latestProcessedDepreciationPeriod();

        if (! $latest) {
            $latest = $start->copy();
        }

        if ($latest->lt($start)) {
            $latest = $start->copy();
        }

        for ($p = $start->copy(); $p->lte($latest); $p->addMonth()) {
            $this->processMonthlyDepr($p->copy());
        }
    }
    private function renderGuardError(\Throwable $e)
    {
        $msg = (string) $e->getMessage();

        if (str_starts_with($msg, 'YEAR_LOCKED|')) {
            [$_, $year] = array_pad(explode('|', $msg), 3, null);
            $year = (int) $year;

            return response()->json([
                'ok' => false,
                'code' => 'YEAR_LOCKED',
                'message' => "Year {$year} already built & locked. Rollback Build Year to reopen.",
                'year' => $year,
            ], 422);
        }

        if (str_starts_with($msg, 'PREV_YEAR_NOT_BUILT|')) {
            [$_, $needPrev, $targetYear] = array_pad(explode('|', $msg), 4, null);

            return response()->json([
                'ok' => false,
                'code' => 'PREV_YEAR_NOT_BUILT',
                'message' => "Year {$targetYear} cannot be processed before Build Year {$needPrev}.",
                'need_build_year' => (int) $needPrev,
                'target_year' => (int) $targetYear,
            ], 422);
        }

        if (str_starts_with($msg, 'PREV_MONTH_NOT_PROCESSED|')) {
            $need = explode('|', $msg)[1] ?? null;

            return response()->json([
                'ok' => false,
                'code' => 'PREV_MONTH_NOT_PROCESSED',
                'message' => 'Previous month depreciation must be processed first.',
                'need_period' => $need,
                'need_period_text' => $need ? Carbon::parse($need)->isoFormat('MMMM YYYY') : null,
            ], 422);
        }

        if (str_starts_with($msg, 'FIRST_RUN_MUST_START_AT_MIN_ELIGIBLE|')) {
            $need = explode('|', $msg)[1] ?? null;

            return response()->json([
                'ok' => false,
                'code' => 'FIRST_RUN_MUST_START_AT_MIN_ELIGIBLE',
                'message' => 'Data bulan ini belum tersedia. Silakan proses periode eligible pertama dulu.',
                'need_period' => $need,
                'need_period_text' => $need ? Carbon::parse($need)->isoFormat('MMMM YYYY') : null,
            ], 422);
        }

        return null;
    }

    private function monthHasEligibleAssets(Carbon $monthStart): bool
    {
        $m = $monthStart->copy()->startOfMonth()->toDateString(); // YYYY-MM-01

        // eligible_start <= monthStart => berarti bulan ini ada aset yang "sudah mulai eligible"
        return DB::table('assets as a')
            ->join('assets_value as av', 'av.asset_uuid', '=', 'a.uuid')
            ->leftJoin('assets_depr_policy as p', 'p.asset_uuid', '=', 'a.uuid')
            ->where('a.kode_status', '!=', 'DIS')
            ->whereNotNull('av.capitalization_date')
            ->whereRaw("
            (
                CASE
                    WHEN EXTRACT(DAY FROM COALESCE(p.depr_start_date, av.capitalization_date)) <= COALESCE(p.cutoff_day, 15)
                        THEN date_trunc('month', COALESCE(p.depr_start_date, av.capitalization_date))::date
                    ELSE (date_trunc('month', COALESCE(p.depr_start_date, av.capitalization_date)) + INTERVAL '1 month')::date
                END
            ) <= ?::date
        ", [$m])
            ->limit(1)
            ->exists();
    }

    protected function canReadDepr(): bool
    {
        $user = auth()->user();
        return $user && $user->hasAction('DEPRECIATION', 'R');
    }
    private function isYearLocked(int $year): bool
    {
        return AssetDeprYearClosing::query()
            ->where('fiscal_year', $year)
            ->where('is_locked', true)
            ->exists();
    }

    private function assertYearOpen(int $year, string $context = 'operation'): void
    {
        if ($this->isYearLocked($year)) {
            throw new \RuntimeException("YEAR_LOCKED|{$year}|{$context}");
        }
    }

    private function assertBuildYearAllowedNow(): void
    {
        // Build Year hanya boleh bulan Desember
        if ((int) now()->month !== 12) {
            throw new \RuntimeException("BUILD_YEAR_ONLY_DECEMBER");
        }
    }

    private function assertDecemberProcessed(int $year): void
    {
        // If the year has no eligible periods at all, allow build-year (it will lock empty yearly summary)
        if (! $this->yearHasEligiblePeriods($year)) {
            return;
        }

        $dec = Carbon::create($year, 12, 1)->startOfMonth()->toDateString();

        $ok = AssetDeprMonthClosing::query()
            ->whereDate('period', $dec)
            ->exists();

        if (! $ok) {
            throw new \RuntimeException("DECEMBER_NOT_PROCESSED|{$dec}");
        }
    }

    private function yearHasEligiblePeriods(int $year): bool
    {
        $start = Carbon::create($year, 1, 1)->startOfMonth()->toDateString();   // YYYY-01-01
        $end   = Carbon::create($year, 12, 1)->startOfMonth()->toDateString();  // YYYY-12-01

        // Eligible start month mengikuti rule cutoff & depr_start_date/policy
        return DB::table('assets as a')
            ->join('assets_value as av', 'av.asset_uuid', '=', 'a.uuid')
            ->leftJoin('assets_depr_policy as p', 'p.asset_uuid', '=', 'a.uuid')
            ->where('a.kode_status', '!=', 'DIS')
            ->whereNotNull('av.capitalization_date')
            ->whereRaw("
            (
                CASE
                    WHEN EXTRACT(DAY FROM COALESCE(p.depr_start_date, av.capitalization_date)) <= COALESCE(p.cutoff_day, 15)
                        THEN date_trunc('month', COALESCE(p.depr_start_date, av.capitalization_date))::date
                    ELSE (date_trunc('month', COALESCE(p.depr_start_date, av.capitalization_date)) + INTERVAL '1 month')::date
                END
            ) BETWEEN ?::date AND ?::date
        ", [$start, $end])
            ->limit(1)
            ->exists();
    }
    private function isYearBuiltLocked(int $year): bool
    {
        return AssetDeprYearClosing::query()
            ->where('fiscal_year', $year)
            ->where('is_locked', true)
            ->exists();
    }

    private function assertPrevYearBuiltUnlessEmpty(int $year, string $context = 'operation'): void
    {
        $prev = $year - 1;
        if ($prev < 1900) return;

        if (! $this->yearHasEligiblePeriods($prev)) {
            return;
        }

        if (! $this->isYearBuiltLocked($prev)) {
            throw new \RuntimeException("PREV_YEAR_NOT_BUILT|{$prev}|{$year}|{$context}");
        }
    }

    private function canBuildYearNowForTarget(int $targetYear): bool
    {
        $now = now();
        $nowYear = (int) $now->year;
        $nowMonth = (int) $now->month;

        // Window build:
        // - Desember tahun target (misal Des 2026 build 2026)
        // - Januari tahun berikutnya (misal Jan 2027 build 2026)
        if ($nowMonth === 12 && $nowYear === $targetYear) return true;
        if ($nowMonth === 1 && $nowYear === ($targetYear + 1)) return true;

        return false;
    }

    private function assertBuildYearAllowedForTarget(int $targetYear): void
    {
        $now = now();
        $nowYear  = (int) $now->year;
        $nowMonth = (int) $now->month;

        $allowed = ($nowMonth === 12 && $nowYear === $targetYear)
            || ($nowMonth === 1 && $nowYear === ($targetYear + 1));

        if (! $allowed) {
            throw new \RuntimeException("BUILD_YEAR_WINDOW_CLOSED|{$targetYear}");
        }
    }

    /**
     * Rollback tidak boleh loncat:
     * - hanya boleh rollback "tahun yang tepat sebelum tahun sekarang" (nowYear-1)
     * - dan harus jadi locked year paling terakhir (max fiscal_year locked)
     */
    private function assertSequentialRollback(int $year): void
    {
        $maxLocked = AssetDeprYearClosing::query()
            ->where('is_locked', true)
            ->max('fiscal_year');

        if ($maxLocked === null) {
            throw new \RuntimeException("ROLLBACK_NO_LOCKED_YEAR");
        }

        if ((int) $maxLocked !== (int) $year) {
            throw new \RuntimeException("ROLLBACK_MUST_BE_LATEST_LOCKED|{$maxLocked}");
        }
    }

    public function index()
    {
        $latestPeriod = $this->latestProcessedDepreciationPeriod();

        return view('depreciation.depreciation', [
            'latestDeprPeriod' => $latestPeriod,
            'latestDeprDate' => $latestPeriod
                ? $latestPeriod->copy()->endOfMonth()->toDateString()
                : now()->toDateString(),
            'latestDeprMonthStart' => $latestPeriod
                ? $latestPeriod->toDateString()
                : now()->startOfMonth()->toDateString(),
            'latestDeprMonthEnd' => $latestPeriod
                ? $latestPeriod->copy()->endOfMonth()->toDateString()
                : now()->endOfMonth()->toDateString(),
        ]);
    }

    public function periodIndex(Request $r)
    {
        abort_unless($this->canReadDepr(), 403);

        // ambil range tahun dari capitalization_date yang benar-benar ada
        $range = DB::table('assets_value as av')
            ->join('assets as a', 'a.uuid', '=', 'av.asset_uuid')
            ->where('a.kode_status', '!=', 'DIS')
            ->whereNotNull('av.capitalization_date')
            ->selectRaw('MIN(EXTRACT(YEAR FROM av.capitalization_date))::int as min_year')
            ->selectRaw('MAX(EXTRACT(YEAR FROM av.capitalization_date))::int as max_year')
            ->first();

        $nowYear = (int) now()->year;

        // range from data (cap_date)
        $dataMin = $range->min_year !== null ? (int) $range->min_year : $nowYear;
        $dataMax = $range->max_year !== null ? (int) $range->max_year : $nowYear;

        // always include current year in dropdown
        $minYear = min($dataMin, $nowYear);
        $maxYear = max($dataMax, $nowYear);

        // year yang dipilih user, fallback ke year terbaru yang ada data (atau current year)
        $year = (int) ($r->input('year') ?: $maxYear);

        // clamp biar ga keluar range
        if ($year < $minYear) $year = $minYear;
        if ($year > $maxYear) $year = $maxYear;

        $isEmpty = !DB::table('assets_depr_month_closings')->limit(1)->exists()
            && !DB::table('assets_depr_ledger_monthly')->limit(1)->exists();

        return view('depreciation.period', [
            'year' => $year,
            'minYear' => $minYear,
            'maxYear' => $maxYear,
            'isLedgerEmpty' => $isEmpty,
            'isDecember' => now()->month === 12,
            'nowYear' => now()->year,
        ]);
    }
    public function dtPeriod(Request $r)
    {
        if (! $this->canReadDepr()) {
            return DataTables::of(collect())->toJson();
        }

        $year = (int) ($r->input('year') ?: now()->year);

        $start = Carbon::create($year, 1, 1)->startOfMonth()->toDateString();  // YYYY-01-01
        $end   = Carbon::create($year, 12, 1)->startOfMonth()->toDateString(); // YYYY-12-01

        $currentMonth = now()->startOfMonth()->toDateString(); // YYYY-MM-01

        $agg = DB::table('assets_depr_ledger_monthly as m')
            ->selectRaw("
            date_trunc('month', m.period)::date as period,
            MAX(m.depr_code) as depr_code,
            COUNT(DISTINCT m.asset_uuid) as asset_count,
            COALESCE(SUM(m.depr_expense),0) as total_depr
        ")
            ->whereBetween('m.period', [$start, $end])
            ->groupByRaw("date_trunc('month', m.period)::date");

        $monthsSql = "(select generate_series(?::date, ?::date, interval '1 month')::date as period) as gs";

        $q = DB::query()
            ->fromRaw($monthsSql, [$start, $end])
            ->leftJoinSub($agg, 'a', fn($j) => $j->on('a.period', '=', 'gs.period'))
            ->leftJoin('assets_depr_month_closings as c', 'c.period', '=', 'gs.period')
            ->leftJoin('assets_depr_month_closings as pc', function ($j) {
                $j->on('pc.period', '=', DB::raw("(gs.period - interval '1 month')::date"));
            })
            ->selectRaw("
            gs.period as period,
            COALESCE(a.depr_code,'') as depr_code,
            COALESCE(a.asset_count,0) as asset_count,
            COALESCE(a.total_depr,0) as total_depr,

            (c.period IS NOT NULL) as is_processed,
            (pc.period IS NOT NULL) as prev_processed,

            (gs.period = ?::date) as is_current,
            (gs.period <= ?::date) as is_past_or_current
        ", [$currentMonth, $currentMonth])
            ->orderBy('gs.period', 'asc');

        return DataTables::of($q)
            ->addColumn('period_ym', fn($row) => Carbon::parse($row->period)->format('Y-m'))
            ->addColumn('depr_code_display', function ($row) {
                $d = Carbon::parse($row->period);
                $generated = 'DEP' . $d->format('ym') . '0001';
                return $row->depr_code ?: $generated;
            })
            ->addColumn('can_click', function ($row) {
                $isProcessed      = (bool) $row->is_processed;
                $prevProcessed    = (bool) $row->prev_processed;
                $pastOrCurrent    = (bool) $row->is_past_or_current;

                // ✅ boleh click kalau:
                // - sudah processed, atau
                // - bulan itu tidak future (<= current month) DAN prev month sudah processed
                return $isProcessed || ($pastOrCurrent && $prevProcessed);
            })
            ->toJson();
    }

    public function initFirstRun(Request $request)
    {
        abort_unless($request->user()?->hasAction('DEPRECIATION', 'C'), 403);

        $already = DB::table('assets_depr_month_closings')->limit(1)->exists()
            || DB::table('assets_depr_ledger_monthly')->limit(1)->exists();

        if ($already) {
            return response()->json([
                'ok' => false,
                'message' => 'Initialize disabled: depreciation data already exists.',
            ], 409);
        }

        $minEligible = DB::table('assets as a')
            ->join('assets_value as av', 'av.asset_uuid', '=', 'a.uuid')
            ->leftJoin('assets_depr_policy as p', 'p.asset_uuid', '=', 'a.uuid')
            ->where('a.kode_status', '!=', 'DIS')
            ->whereNotNull('av.capitalization_date')
            ->selectRaw("
            MIN(
                CASE
                    WHEN EXTRACT(DAY FROM av.capitalization_date) <= COALESCE(p.cutoff_day, 15)
                        THEN (date_trunc('month', av.capitalization_date) + INTERVAL '0 month')::date
                    ELSE (date_trunc('month', av.capitalization_date) + INTERVAL '1 month')::date
                END
            ) as min_eligible
        ")
            ->value('min_eligible');

        if (!$minEligible) {
            return response()->json([
                'ok' => false,
                'message' => 'Cannot initialize: no capitalization_date found in assets_value.',
            ], 422);
        }

        $firstPeriod = Carbon::parse($minEligible)->startOfMonth();

        try {
            $this->processMonthlyDepr($firstPeriod);

            return response()->json([
                'ok' => true,
                'period' => $firstPeriod->toDateString(),
                'period_month' => $firstPeriod->format('Y-m'),
                'message' => 'Initialized depreciation first run successfully.',
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage() ?: 'Initialize failed.',
            ], 500);
        }
    }


    public function dtPolicies(Request $r)
    {
        if (!$this->canReadDepr()) {
            return DataTables::of(collect())->toJson();
        }
        $q = AssetDeprPolicy::query()
            ->with('asset:uuid,asset_code,description')
            ->when(
                $r->asset_q,
                fn($qq) =>
                $qq->whereHas('asset', function ($qa) use ($r) {
                    $s = trim($r->asset_q);
                    $qa->where('asset_code', 'ilike', "%{$s}%")
                        ->orWhere('description', 'ilike', "%{$s}%");
                })
            );

        return DataTables::eloquent($q)
            ->addColumn('asset_code', fn($row) => $row->asset?->asset_code)
            ->addColumn('asset_name', fn($row) => $row->asset?->description)
            ->toJson();
    }

    public function dtMonthly(Request $r)
    {
        if (!$this->canReadDepr()) {
            return DataTables::of(collect())->toJson();
        }

        // =========================
        // NEW: PERIOD RANGE FILTER
        // =========================
        // Expect: period_from=YYYY-MM, period_to=YYYY-MM
        $periodFromMonth = trim((string) $r->input('period_from', '')); // ex: 2026-01
        $periodToMonth   = trim((string) $r->input('period_to', ''));   // ex: 2026-03

        // Default: current month only
        $from = $periodFromMonth !== ''
            ? Carbon::createFromFormat('Y-m', $periodFromMonth)->startOfMonth()
            : now()->startOfMonth();

        $to = $periodToMonth !== ''
            ? Carbon::createFromFormat('Y-m', $periodToMonth)->startOfMonth()
            : $from->copy();

        // Normalize if reversed
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $fromDate = $from->toDateString(); // YYYY-MM-01
        $toDate   = $to->toDateString();   // YYYY-MM-01

        // For "Ending Balance prev year" join (single year reference)
        $periodYear = (int) $to->year;
        $prevYear   = $periodYear - 1;

        // =========================
        // QUERY
        // =========================
        $q = AssetDeprMonthly::query()
            ->with('asset:uuid,asset_code,description,kode_status')
            ->leftJoin('assets_value as av', 'av.asset_uuid', '=', 'assets_depr_ledger_monthly.asset_uuid')
            ->leftJoin('assets_depr_policy as p', 'p.asset_uuid', '=', 'assets_depr_ledger_monthly.asset_uuid')
            ->leftJoin('assets_depr_yearly as y', function ($j) use ($prevYear) {
                $j->on('y.asset_uuid', '=', 'assets_depr_ledger_monthly.asset_uuid')
                    ->where('y.fiscal_year', '=', $prevYear);
            })
            // RANGE FILTER
            ->whereBetween('assets_depr_ledger_monthly.period', [$fromDate, $toDate])
            ->select([
                'assets_depr_ledger_monthly.uuid',
                'assets_depr_ledger_monthly.asset_uuid',
                'assets_depr_ledger_monthly.period',
                'assets_depr_ledger_monthly.opening_balance',
                'assets_depr_ledger_monthly.additions',
                'assets_depr_ledger_monthly.transfers_in',
                'assets_depr_ledger_monthly.transfers_out',
                'assets_depr_ledger_monthly.disposals',
                'assets_depr_ledger_monthly.adjustment_value',
                'assets_depr_ledger_monthly.adjustment_depreciation',
                'assets_depr_ledger_monthly.depr_expense',
                'assets_depr_ledger_monthly.accumulated_depr_end',
                'assets_depr_ledger_monthly.ending_balance',
                'assets_depr_ledger_monthly.depr_code',
                'av.capitalization_date as cap_date',
                'av.total as total_value',
                'y.ending_balance_year as ending_balance_prev_year',
                DB::raw("COALESCE(av.total, 0) - COALESCE(assets_depr_ledger_monthly.accumulated_depr_end, 0) AS last_net_book_value"),
                DB::raw("
                CASE
                    WHEN av.capitalization_date IS NULL THEN NULL
                    ELSE
                    (date_trunc('month', assets_depr_ledger_monthly.period))::date
                END AS period_depr
            "),
                DB::raw("
                COALESCE(
                    NULLIF(p.useful_life_months, 0),
                    NULLIF(av.useful_life_month, 0),
                    CASE WHEN av.useful_life_year IS NOT NULL
                        THEN (av.useful_life_year * 12)::int
                        ELSE 0 END,
                    0
                ) AS useful_life_months
            "),
                DB::raw("
CASE
  WHEN COALESCE(p.depr_start_date, av.capitalization_date, av.actual_date, av.created_at) IS NULL
    THEN 0
  ELSE
    GREATEST(
      (
        COALESCE(
          NULLIF(p.useful_life_months, 0),
          NULLIF(av.useful_life_month, 0),
          CASE
            WHEN av.useful_life_year IS NOT NULL
              THEN (av.useful_life_year * 12)::int
            ELSE 0
          END,
          60
        )
        -
        (
          SELECT COUNT(*)
          FROM assets_depr_ledger_monthly m2
          WHERE m2.asset_uuid = assets_depr_ledger_monthly.asset_uuid
            AND date_trunc('month', m2.period)::date <= date_trunc('month', assets_depr_ledger_monthly.period)::date
            AND date_trunc('month', m2.period)::date >=
              CASE
                WHEN EXTRACT(DAY FROM COALESCE(p.depr_start_date, av.capitalization_date, av.actual_date, av.created_at)) <= COALESCE(p.cutoff_day, 15)
                  THEN date_trunc('month', COALESCE(p.depr_start_date, av.capitalization_date, av.actual_date, av.created_at))::date
                ELSE (date_trunc('month', COALESCE(p.depr_start_date, av.capitalization_date, av.actual_date, av.created_at)) + INTERVAL '1 month')::date
              END
            AND (
              COALESCE(m2.depr_expense, 0) <> 0
              OR COALESCE(m2.adjustment_depreciation, 0) <> 0
              OR COALESCE(m2.accumulated_depr_end, 0) <> 0
            )
        )
      ),
      0
    )
END AS remaining_useful_life_months
")
            ]);

        if ($status = $r->get('asset_status')) {
            $q->whereHas('asset', function ($qa) use ($status) {
                $qa->where('kode_status', $status);
            });
        }

        if ($capFrom = $r->get('cap_from')) {
            $q->whereDate('av.capitalization_date', '>=', $capFrom);
        }
        if ($capTo = $r->get('cap_to')) {
            $q->whereDate('av.capitalization_date', '<=', $capTo);
        }

        if ($assetQ = trim((string) $r->get('asset_q', ''))) {
            $q->whereHas('asset', function ($qa) use ($assetQ) {
                $qa->where('asset_code', 'ilike', "%{$assetQ}%")
                    ->orWhere('description', 'ilike', "%{$assetQ}%");
            });
        }

        // Total depreciation for current filtered range
        $totalDepr = (clone $q)->sum('assets_depr_ledger_monthly.depr_expense');

        return DataTables::of($q)
            ->addColumn('asset_code', fn($row) => $row->asset?->asset_code)
            ->addColumn('asset_name', fn($row) => $row->asset?->description)
            ->addColumn('asset_uuid', fn($row) => $row->asset_uuid)
            ->addColumn('asset_kode_status', fn($row) => $row->asset?->kode_status)
            ->addColumn('asset_status_label', function ($row) {
                static $statusMap = null;

                if ($statusMap === null) {
                    $statusMap = MasterStatus::pluck('name', 'kode')->toArray();
                }

                $kode = $row->asset?->kode_status;
                if (!$kode) return null;

                $name = $statusMap[$kode] ?? null;
                return $name ? "{$kode} - {$name}" : $kode;
            })

            ->filterColumn('asset_code', function ($query, $keyword) {
                $keyword = trim($keyword);
                $query->whereHas('asset', function ($qa) use ($keyword) {
                    $qa->where('asset_code', 'ilike', "%{$keyword}%");
                });
            })
            ->filterColumn('asset_name', function ($query, $keyword) {
                $keyword = trim($keyword);
                $query->whereHas('asset', function ($qa) use ($keyword) {
                    $qa->where('description', 'ilike', "%{$keyword}%");
                });
            })
            ->filterColumn('asset_status_label', function ($query, $keyword) {
                $keyword = trim($keyword);
                $query->whereHas('asset', function ($qa) use ($keyword) {
                    $qa->where('kode_status', 'ilike', "%{$keyword}%");
                });
            })
            ->filterColumn('cap_date', function ($query, $keyword) {
                $keyword = trim($keyword);
                $query->whereRaw("to_char(av.capitalization_date,'YYYY-MM-DD') ILIKE ?", ["%{$keyword}%"]);
            })

            ->with('total_depr', (float) $totalDepr)
            ->toJson();
    }


    public function dtYearly(Request $r)
    {
        if (!$this->canReadDepr()) {
            return DataTables::of(collect())->toJson();
        }
        $q = AssetDeprYearly::query()
            ->with('asset:uuid,asset_code,description')
            ->when(
                $r->year,
                fn($qq) => $qq->where('fiscal_year', (int)$r->year)
            )
            ->when(
                $r->asset_q,
                fn($qq) =>
                $qq->whereHas('asset', function ($qa) use ($r) {
                    $s = trim($r->asset_q);
                    $qa->where('asset_code', 'ilike', "%{$s}%")
                        ->orWhere('description', 'ilike', "%{$s}%");
                })
            );

        return DataTables::eloquent($q)
            ->addColumn('asset_code', fn($row) => $row->asset?->asset_code)
            ->addColumn('asset_name', fn($row) => $row->asset?->description)
            ->toJson();
    }

    // public function runMonth(Request $request)
    // {
    //     abort_unless($request->user()?->hasAction('DEPRECIATION', 'C'), 403);

    //     $to = $request->filled('period')
    //         ? Carbon::parse($request->input('period'))->startOfMonth()
    //         : now()->startOfMonth();

    //     $fromPolicy = AssetDeprPolicy::whereNotNull('depr_start_date')
    //         ->min(DB::raw("date_trunc('month', depr_start_date)::date"));

    //     $from = $fromPolicy
    //         ? Carbon::parse($fromPolicy)->startOfMonth()
    //         : $to->copy();

    //     $this->processMonthlyDeprRange($from, $to);

    //     return response()->json(['ok' => true]);
    // }

    // private function processMonthlyDeprRange(Carbon $from, Carbon $to): void
    // {
    //     $from = $from->copy()->startOfMonth();
    //     $to   = $to->copy()->startOfMonth();

    //     if ($from->gt($to)) {
    //         return;
    //     }

    //     while ($from->lte($to)) {
    //         $this->processMonthlyDepr($from);
    //         $from->addMonth();
    //     }
    // }
    private function ensurePrevMonthProcessed(Carbon $period): void
    {
        $prev = $period->copy()->subMonth()->startOfMonth();

        // Kalau bulan sebelumnya memang tidak ada aset eligible, jangan dipaksa ada closing.
        if (! $this->monthHasEligibleAssets($prev)) {
            return;
        }

        $exists = AssetDeprMonthClosing::query()
            ->whereDate('period', $prev->toDateString())
            ->exists();

        if (! $exists) {
            throw new \RuntimeException("PREV_MONTH_NOT_PROCESSED|{$prev->toDateString()}");
        }
    }

    public function runMonth(Request $request)
    {
        abort_unless($request->user()?->hasAction('DEPRECIATION', 'C'), 403);

        $periodInput = $request->input('period');
        $period = $periodInput
            ? Carbon::parse($periodInput)->startOfMonth()
            : now()->startOfMonth();

        try {
            $this->guardWritablePeriod($period, 'runMonth', [
                'require_prev_month' => true,
                'enforce_first_run_min_eligible' => true,
            ]);

            $this->processMonthlyDepr($period);

            return response()->json([
                'ok' => true,
                'period' => $period->toDateString(),
            ]);
        } catch (\Throwable $e) {
            if ($resp = $this->renderGuardError($e)) return $resp;

            report($e);
            return response()->json([
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'message' => $e->getMessage() ?: 'Failed to process depreciation.',
            ], 500);
        }
    }


    private function processMonthlyDepr(Carbon $period): void
    {
        DB::transaction(function () use ($period) {
            $assets = DB::table('assets')
                ->join('assets_value AS av', 'av.asset_uuid', '=', 'assets.uuid')
                ->leftJoin('assets_depr_policy AS p', 'p.asset_uuid', '=', 'assets.uuid')
                ->select(
                    'assets.uuid AS asset_uuid',
                    'av.capitalization_date',
                    'av.actual_date',
                    'av.created_at AS av_created_at',
                    'av.useful_life_month',
                    'av.useful_life_year',

                    'p.asset_uuid         AS has_policy',
                    'p.depr_start_date    AS policy_depr_start_date',
                    'p.useful_life_months AS policy_life_months',
                    'p.cutoff_day         AS policy_cutoff_day'
                )
                ->where('assets.kode_status', '!=', 'DIS')
                ->get();

            foreach ($assets as $row) {
                $startFromAv = $row->capitalization_date
                    ?: ($row->actual_date ?: $row->av_created_at);

                $lifeFromAv = (int) ($row->useful_life_month ?? 0);
                if ($lifeFromAv <= 0 && isset($row->useful_life_year)) {
                    $lifeFromAv = (int) round(((float) $row->useful_life_year) * 12);
                }
                if ($lifeFromAv <= 0) {
                    $lifeFromAv = 60;
                }

                if (!$row->has_policy) {
                    if (!$startFromAv) {
                        continue;
                    }

                    AssetDeprPolicy::create([
                        'asset_uuid'         => $row->asset_uuid,
                        'method'             => AssetDeprPolicy::METHOD_SL,
                        'useful_life_months' => $lifeFromAv,
                        'salvage_value'      => 0,
                        'depr_start_date'    => Carbon::parse($startFromAv)->toDateString(),
                        'convention'         => AssetDeprPolicy::CONVENTION_PRORATA_MONTH,
                        'cutoff_day'         => 15,
                        'start_rule'         => 'CUT_OFF_NEXT_OR_NEXT2',
                        'is_active'          => true,
                    ]);

                    continue;
                }

                if ($startFromAv) {
                    $newStart = Carbon::parse($startFromAv)->toDateString();

                    if (
                        !$row->policy_depr_start_date ||
                        Carbon::parse($newStart)->lt(Carbon::parse($row->policy_depr_start_date))
                    ) {
                        AssetDeprPolicy::where('asset_uuid', $row->asset_uuid)
                            ->update(['depr_start_date' => $newStart]);
                    }
                }

                if ($lifeFromAv > 0 && (int) $row->policy_life_months <= 0) {
                    AssetDeprPolicy::where('asset_uuid', $row->asset_uuid)
                        ->update(['useful_life_months' => $lifeFromAv]);
                }
            }

            $policies = AssetDeprPolicy::with('asset')
                ->where('is_active', true)
                ->whereHas('asset', function ($q) {
                    $q->where('kode_status', '!=', 'DIS');
                })
                ->get();

            foreach ($policies as $policy) {
                $assetUuid = $policy->asset_uuid;

                $av = DB::table('assets_value')
                    ->select(
                        'capitalization_date',
                        'actual_date',
                        'created_at',
                        'total',
                        'useful_life_month',
                        'useful_life_year'
                    )
                    ->where('asset_uuid', $assetUuid)
                    ->first();

                $capDate  = $av?->capitalization_date ?? $av?->actual_date ?? $av?->created_at;
                $capTotal = (float) ($av?->total ?? 0);

                if (!$capDate) {
                    continue;
                }

                $assetStartMonth = Carbon::parse($capDate)->startOfMonth();
                if ($period->lt($assetStartMonth)) {
                    continue;
                }

                $prev = AssetDeprMonthly::where('asset_uuid', $assetUuid)
                    ->whereDate('period', '>=', $assetStartMonth->toDateString())
                    ->whereDate('period', '<', $period->toDateString())
                    ->orderBy('period', 'desc')
                    ->first();

                $opening = (float) ($prev->ending_balance ?? $capTotal);
                $accPrev = (float) ($prev->accumulated_depr_end ?? 0.0);

                $additions = 0.0;

                $tin = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                    ->whereDate('period', $period)
                    ->where('category', AssetDeprMovement::TRANSFER_IN)
                    ->sum('amount');

                $tout = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                    ->whereDate('period', $period)
                    ->where('category', AssetDeprMovement::TRANSFER_OUT)
                    ->sum('amount');

                $disposals = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                    ->whereDate('period', $period)
                    ->where('category', AssetDeprMovement::DISPOSAL)
                    ->sum('amount');

                $adjValue = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                    ->whereDate('period', $period)
                    ->where('category', AssetDeprMovement::ADJUSTMENT_VALUE)
                    ->sum('amount');

                $adjDepr = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                    ->whereDate('period', $period)
                    ->where('category', AssetDeprMovement::ADJUSTMENT_DEPRECIATION)
                    ->sum('amount');

                $startBase = $policy->depr_start_date
                    ?: Carbon::parse($capDate)->toDateString();

                $cutoffDay = (int) ($policy->cutoff_day ?? 15);

                $eligibleStart = Carbon::parse($startBase)->startOfMonth()->addMonths(
                    Carbon::parse($startBase)->day <= $cutoffDay ? 0 : 1
                );

                $eligible = $eligibleStart->lte($period);

                $lifeMonths = (int) ($policy->useful_life_months ?: 0);
                if ($lifeMonths <= 0) {
                    $lifeMonths = (int) ($av?->useful_life_month ?? 0);
                }
                if ($lifeMonths <= 0 && isset($av?->useful_life_year)) {
                    $lifeMonths = (int) round(((float) $av->useful_life_year) * 12);
                }
                if ($lifeMonths <= 0) {
                    $lifeMonths = 60;
                }

                // jumlah bulan yang benar-benar sudah terdepresiasi sebelum period ini
                $usedMonths = 0;
                if ($eligible) {
                    $usedMonths = (int) DB::table('assets_depr_ledger_monthly as m')
                        ->where('m.asset_uuid', $assetUuid)
                        ->whereDate('m.period', '>=', $eligibleStart->toDateString())
                        ->whereDate('m.period', '<', $period->toDateString())
                        ->where(function ($q) {
                            $q->whereRaw('COALESCE(m.depr_expense,0) <> 0')
                                ->orWhereRaw('COALESCE(m.adjustment_depreciation,0) <> 0')
                                ->orWhereRaw('COALESCE(m.accumulated_depr_end,0) <> 0');
                        })
                        ->count();
                }

                $remaining = $eligible ? max(0, $lifeMonths - $usedMonths) : 0;
                $salvage   = (float) $policy->salvage_value;

                // base tetap dari acquisition awal
                $baseCost = max($capTotal - $salvage, 0.0);

                // monthly depreciation flat
                $monthlyFixed = $lifeMonths > 0
                    ? floor($baseCost / $lifeMonths)
                    : 0.0;

                $hasBasisChangeThisMonth =
                    abs($tin) > 0.00001 ||
                    abs($tout) > 0.00001 ||
                    abs($disposals) > 0.00001 ||
                    abs($adjValue) > 0.00001;

                if (!$eligible || $remaining <= 0) {
                    $deprExpense = 0.0;
                } else {
                    if ($hasBasisChangeThisMonth) {
                        $recalcBase = max(
                            ($opening + $adjValue + $tin - $tout - $disposals) - $salvage,
                            0.0
                        );

                        $deprExpense = floor($recalcBase / $remaining);
                    } else {
                        $deprExpense = $monthlyFixed;
                    }

                    $maxAllowed = max(
                        ($opening + $adjValue + $tin - $tout - $disposals) - $salvage,
                        0.0
                    );

                    $deprExpense = min($deprExpense, $maxAllowed);
                }

                $ending = $opening
                    + $additions
                    + $tin
                    - $tout
                    - $disposals
                    + $adjValue
                    - $deprExpense
                    + $adjDepr;

                $accEnd = $accPrev + $deprExpense + $adjDepr;
                $txCode = 'DEP' . $period->format('ym') . '0001';

                AssetDeprMonthly::updateOrCreate(
                    [
                        'asset_uuid' => $assetUuid,
                        'period'     => $period->toDateString(),
                    ],
                    [
                        'opening_balance'         => $opening,
                        'additions'               => $additions,
                        'transfers_in'            => $tin,
                        'transfers_out'           => $tout,
                        'disposals'               => $disposals,
                        'adjustment_value'        => $adjValue,
                        'adjustment_depreciation' => $adjDepr,
                        'depr_expense'            => $deprExpense,
                        'accumulated_depr_end'    => $accEnd,
                        'ending_balance'          => $ending,
                        'depr_code'               => $txCode,
                    ]
                );
            }

            $rowCount = AssetDeprMonthly::query()
                ->whereDate('period', $period->toDateString())
                ->count();

            AssetDeprMonthClosing::updateOrCreate(
                ['period' => $period->toDateString()],
                [
                    'row_count'    => (int) $rowCount,
                    'processed_by' => auth()->user()?->name,
                    'processed_at' => now(),
                ]
            );
        });
    }


    private function canForceBuildYear(): bool
    {
        $u = auth()->user();
        return $u && $u->hasAction('DEPRECIATION', 'FORCE_BUILD_YEAR');
    }
    public function buildYear(Request $request)
    {
        abort_unless($request->user()?->hasAction('DEPRECIATION', 'C'), 403);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:3000'],
        ]);

        $year = (int) $data['year'];
        $nowYear = (int) now()->year;
        $nowMonth = (int) now()->month;

        try {
            // 1) year must be open
            $this->assertYearOpen($year, 'buildYear');

            // 2) chain gate: prev year must be built unless empty eligible
            $this->assertPrevYearBuiltUnlessEmpty($year, 'buildYear');

            // 3) must have Dec processed
            $this->assertDecemberProcessed($year);

            // 4) time rule: only applies when building CURRENT year
            if ($year === $nowYear && $nowMonth !== 12) {
                throw new \RuntimeException("BUILD_YEAR_ONLY_DECEMBER_CURRENT|{$year}");
            }

            // 5) block future year
            if ($year > $nowYear) {
                throw new \RuntimeException("BUILD_YEAR_FUTURE_NOT_ALLOWED|{$year}");
            }
        } catch (\Throwable $e) {

            if (str_starts_with($e->getMessage(), 'BUILD_YEAR_ONLY_DECEMBER_CURRENT|')) {
                return response()->json([
                    'ok' => false,
                    'code' => 'BUILD_YEAR_ONLY_DECEMBER_CURRENT',
                    'message' => "Build Year {$year} hanya boleh dilakukan di bulan Desember untuk tahun berjalan.",
                    'year' => $year,
                ], 422);
            }

            if (str_starts_with($e->getMessage(), 'PREV_YEAR_NOT_BUILT|')) {
                [$_, $needPrev, $targetYear] = array_pad(explode('|', $e->getMessage()), 4, null);
                return response()->json([
                    'ok' => false,
                    'code' => 'PREV_YEAR_NOT_BUILT',
                    'message' => "Year {$targetYear} tidak bisa dibuild sebelum Build Year {$needPrev}.",
                    'need_build_year' => (int) $needPrev,
                    'target_year' => (int) $targetYear,
                ], 422);
            }

            if (str_starts_with($e->getMessage(), 'YEAR_LOCKED|')) {
                return response()->json([
                    'ok' => false,
                    'code' => 'YEAR_LOCKED',
                    'message' => "Year {$year} sudah di-build & dikunci. Rollback dulu kalau mau ubah.",
                    'year' => $year,
                ], 422);
            }

            if (str_starts_with($e->getMessage(), 'DECEMBER_NOT_PROCESSED|')) {
                $need = explode('|', $e->getMessage())[1] ?? null;
                return response()->json([
                    'ok' => false,
                    'code' => 'DECEMBER_NOT_PROCESSED',
                    'message' => 'Build Year butuh periode Desember sudah diproses dulu.',
                    'need_period' => $need,
                    'need_period_text' => $need ? Carbon::parse($need)->isoFormat('MMMM YYYY') : null,
                ], 422);
            }

            if (str_starts_with($e->getMessage(), 'BUILD_YEAR_FUTURE_NOT_ALLOWED|')) {
                return response()->json([
                    'ok' => false,
                    'code' => 'BUILD_YEAR_FUTURE_NOT_ALLOWED',
                    'message' => "Tidak bisa Build Year untuk tahun masa depan.",
                    'year' => $year,
                ], 422);
            }

            report($e);
            return response()->json([
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'message' => $e->getMessage() ?: 'Build year failed.',
            ], 500);
        }

        DB::transaction(function () use ($year) {
            $start = "{$year}-01-01";
            $end   = "{$year}-12-31";

            $assetUuids = AssetDeprMonthly::query()
                ->whereBetween('period', [$start, $end])
                ->distinct()
                ->pluck('asset_uuid');

            foreach ($assetUuids as $assetUuid) {
                $rows = AssetDeprMonthly::query()
                    ->where('asset_uuid', $assetUuid)
                    ->whereBetween('period', [$start, $end])
                    ->orderBy('period', 'asc')
                    ->get();

                if ($rows->isEmpty()) {
                    continue;
                }

                $first = $rows->first();
                $last  = $rows->last();

                $totalAdditions = $rows->sum(function ($r) {
                    return
                        (float) $r->additions +
                        (float) $r->transfers_in -
                        (float) $r->transfers_out -
                        (float) $r->disposals +
                        (float) $r->adjustment_value;
                });

                AssetDeprYearly::updateOrCreate(
                    [
                        'asset_uuid'  => $assetUuid,
                        'fiscal_year' => $year,
                    ],
                    [
                        'opening_balance'              => (float) $first->opening_balance,
                        'total_additions'              => (float) $totalAdditions,
                        'depr_expense_year'            => (float) $rows->sum('depr_expense'),
                        'adjustment_depreciation_year' => (float) $rows->sum('adjustment_depreciation'),
                        'accumulated_depr_end'         => (float) $last->accumulated_depr_end,
                        'ending_balance_year'          => (float) $last->ending_balance,
                    ]
                );
            }

            AssetDeprYearClosing::updateOrCreate(
                ['fiscal_year' => $year],
                [
                    'is_locked' => true,
                    'built_by'  => auth()->user()?->name,
                    'built_at'  => now(),
                ]
            );
        });

        return response()->json([
            'ok'      => true,
            'message' => "Year {$year} built & locked.",
            'year'    => $year,
        ]);
    }

    public function yearStatus(Request $r)
    {
        abort_unless($this->canReadDepr(), 403);

        $year = (int) ($r->input('year') ?: now()->year);
        $nowYear = (int) now()->year;
        $nowMonth = (int) now()->month;

        $locked = AssetDeprYearClosing::query()
            ->where('fiscal_year', $year)
            ->where('is_locked', true)
            ->exists();

        // Dec processed? (or year has no eligible assets -> treat as OK)
        $dec = Carbon::create($year, 12, 1)->startOfMonth()->toDateString();
        $hasEligible = $this->yearHasEligiblePeriods($year);
        $decProcessed = !$hasEligible ? true : AssetDeprMonthClosing::query()->whereDate('period', $dec)->exists();

        // Chain gate ok?
        $prevOk = true;
        try {
            $this->assertPrevYearBuiltUnlessEmpty($year, 'yearStatus');
        } catch (\Throwable $e) {
            $prevOk = false;
        }

        // Time rule: only for current year
        $timeOk = true;
        if ($year === $nowYear) {
            $timeOk = ($nowMonth === 12);
        }
        if ($year > $nowYear) {
            $timeOk = false;
        }

        $canBuildNow = (!$locked) && $decProcessed && $prevOk && $timeOk;

        return response()->json([
            'ok' => true,
            'year' => $year,
            'locked' => $locked,
            'december_processed' => $decProcessed,
            'need_dec_period' => $dec,
            'prev_year_ok' => $prevOk,
            'time_ok' => $timeOk,
            'can_build_now' => $canBuildNow,
            'now_year' => $nowYear,
            'now_month' => $nowMonth,
        ]);
    }


    public function rollbackYear(Request $request)
    {
        abort_unless($request->user()?->hasAction('DEPRECIATION', 'C'), 403);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:3000'],
        ]);

        $year = (int) $data['year'];

        try {
            $this->assertSequentialRollback($year);
        } catch (\Throwable $e) {

            if (str_starts_with($e->getMessage(), 'ROLLBACK_NO_LOCKED_YEAR')) {
                return response()->json([
                    'ok' => false,
                    'code' => 'ROLLBACK_NO_LOCKED_YEAR',
                    'message' => "Tidak ada year yang sedang LOCKED untuk di-rollback.",
                ], 422);
            }

            if (str_starts_with($e->getMessage(), 'ROLLBACK_MUST_BE_LATEST_LOCKED|')) {
                $need = explode('|', $e->getMessage())[1] ?? null;

                return response()->json([
                    'ok' => false,
                    'code' => 'ROLLBACK_MUST_BE_LATEST_LOCKED',
                    'message' => "Rollback tidak boleh loncat. Harus rollback locked year paling terakhir: {$need}.",
                    'latest_locked_year' => $need !== null ? (int) $need : null,
                ], 422);
            }

            throw $e;
        }

        DB::transaction(function () use ($year) {
            AssetDeprYearly::query()
                ->where('fiscal_year', $year)
                ->delete();

            AssetDeprYearClosing::updateOrCreate(
                ['fiscal_year' => $year],
                [
                    'is_locked' => false,
                    'rolled_back_by' => auth()->user()?->name,
                    'rolled_back_at' => now(),
                ]
            );
        });

        return response()->json([
            'ok' => true,
            'message' => "Year {$year} unlocked (rollback build year).",
            'year' => $year,
        ]);
    }



    public function recordAddition(Request $r)
    {
        $data = $r->validate([
            'asset_uuid'  => ['required', 'uuid'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'actual_date' => ['required', 'date'],
            'note'        => ['nullable', 'string', 'max:300'],
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_uuid' => ['nullable', 'uuid'],
            'group_uuid'  => ['nullable', 'uuid'],
        ]);

        return $this->writeMovementWithCutoff($data, AssetDeprMovement::ADDITION);
    }

    public function recordTransfer(Request $r)
    {
        $data = $r->validate([
            'transfer_type'   => ['nullable', 'in:tf-val,' . self::TRANSFER_TYPE_CARRY_OVER . ',' . self::TRANSFER_TYPE_ACQ_FIX],
            'from_asset_uuid' => ['required', 'uuid'],
            'to_asset_uuid'   => ['required', 'uuid', 'different:from_asset_uuid'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'actual_date'     => ['required', 'date'],
            'note'            => ['nullable', 'string', 'max:300'],
            'source_type'     => ['nullable', 'string', 'max:64'],
            'source_uuid'     => ['nullable', 'uuid'],
            'group_uuid'      => ['nullable', 'uuid'],
        ]);

        $msg = $this->applyTransfer($data);

        return response()->json([
            'ok'         => true,
            'message'    => $msg,
        ]);
    }

    public function recordDisposal(Request $r)
    {
        $data = $r->validate([
            'asset_uuid'  => ['required', 'uuid'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'actual_date' => ['required', 'date'],
            'note'        => ['nullable', 'string', 'max:300'],
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_uuid' => ['nullable', 'uuid'],
            'group_uuid'  => ['nullable', 'uuid'],
        ]);

        $period = Carbon::parse($data['actual_date'])->startOfMonth();

        AssetDeprMovement::create([
            'asset_uuid'        => $data['asset_uuid'],
            'period'            => $period,
            'category'          => AssetDeprMovement::DISPOSAL,
            'amount'            => $data['amount'],
            'depr_start_period' => $period,
            'group_uuid'        => $data['group_uuid'] ?? null,
            'source_type'       => $data['source_type'] ?? 'manual',
            'source_uuid'       => $data['source_uuid'] ?? null,
            'note'              => $data['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'message' => 'Disposal recorded']);
    }

    public function recordAdjustmentValue(Request $r)
    {
        $data = $r->validate([
            'asset_uuid'  => ['required', 'uuid'],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'actual_date' => ['required', 'date'],
            'note'        => ['nullable', 'string', 'max:300'],
        ]);

        return $this->writeMovementWithCutoff($data, AssetDeprMovement::ADJUSTMENT_VALUE);
    }

    public function recordAdjustmentDepreciation(Request $r)
    {
        abort_unless($r->user()?->hasAction('DEPRECIATION', 'C'), 403);

        $data = $r->validate([
            'asset_uuid'  => ['required', 'uuid'],
            'amount'      => ['required', 'numeric', 'not_in:0'],
            'actual_date' => ['required', 'date'],
            'note'        => ['nullable', 'string', 'max:300'],
        ]);

        $actual = Carbon::parse($data['actual_date'])->startOfDay();
        $period = $actual->copy()->startOfMonth();

        $rangeStart = now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
        $rangeEnd   = now()->endOfMonth()->endOfDay();

        if ($actual->lt($rangeStart) || $actual->gt($rangeEnd)) {
            return response()->json([
                'ok' => false,
                'code' => 'ADJ_DATE_OUT_OF_RANGE',
                'message' => 'Adjustment Depreciation hanya bisa dilakukan untuk bulan sekarang atau bulan sebelumnya.',
                'allowed_from' => $rangeStart->toDateString(),
                'allowed_to' => $rangeEnd->toDateString(),
                'allowed_text' => $rangeStart->isoFormat('MMMM YYYY') . ' - ' . $rangeEnd->isoFormat('MMMM YYYY'),
            ], 422);
        }

        $latestPeriod = $this->latestProcessedDepreciationPeriod();

        if (! $latestPeriod) {
            return response()->json([
                'ok' => false,
                'code' => 'NO_DEPRECIATION_PROCESSED',
                'message' => 'Belum ada periode depresiasi yang diproses. Jalankan Process Depreciation Month dulu.',
            ], 422);
        }

        if ($period->gt($latestPeriod)) {
            return response()->json([
                'ok' => false,
                'code' => 'PERIOD_NOT_PROCESSED',
                'message' => 'Periode adjustment belum diproses depresiasinya.',
                'need_period' => $period->toDateString(),
                'latest_period' => $latestPeriod->toDateString(),
                'latest_period_text' => $latestPeriod->isoFormat('MMMM YYYY'),
            ], 422);
        }

        try {
            $closingExists = AssetDeprMonthClosing::query()
                ->whereDate('period', $period->toDateString())
                ->exists();

            if (! $closingExists) {
                return response()->json([
                    'ok' => false,
                    'code' => 'PERIOD_NOT_CLOSED',
                    'message' => 'Periode adjustment belum diproses/closing.',
                    'need_period' => $period->toDateString(),
                    'need_period_text' => $period->isoFormat('MMMM YYYY'),
                ], 422);
            }

            $this->guardWritablePeriod($period, 'adjDepr', [
                'require_prev_month' => true,
                'enforce_first_run_min_eligible' => false,
            ]);

            AssetDeprMovement::create([
                'asset_uuid'        => $data['asset_uuid'],
                'period'            => $period,
                'category'          => AssetDeprMovement::ADJUSTMENT_DEPRECIATION,
                'amount'            => (float) $data['amount'],
                'depr_start_period' => $period,
                'source_type'       => 'manual',
                'note'              => $data['note'] ?? null,
            ]);

            // Kalau adjustment dibuat di bulan sebelumnya,
            // bulan setelahnya harus ikut dihitung ulang karena opening/accum/ending bisa berubah.
            $this->reprocessDepreciationFrom($period);

            return response()->json([
                'ok' => true,
                'message' => 'Depreciation adjustment recorded.',
                'period' => $period->toDateString(),
                'period_text' => $period->isoFormat('MMMM YYYY'),
            ]);
        } catch (\Throwable $e) {
            if ($resp = $this->renderGuardError($e)) return $resp;

            report($e);

            return response()->json([
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'message' => $e->getMessage() ?: 'Failed to save adjustment.',
            ], 500);
        }
    }


    private function writeMovementWithCutoff(array $data, string $category)
    {
        $period = Carbon::parse($data['actual_date'])->startOfMonth();
        $policy = AssetDeprPolicy::where('asset_uuid', $data['asset_uuid'])->where('is_active', true)->first();

        $deprStart = $this->calcDeprStartPeriod($data['actual_date'], $policy?->cutoff_day ?? 15);

        AssetDeprMovement::create([
            'asset_uuid'        => $data['asset_uuid'],
            'period'            => $period,
            'category'          => $category,
            'amount'            => $data['amount'],
            'depr_start_period' => $deprStart,
            'source_type'       => $data['source_type'] ?? 'manual',
            'source_uuid'       => $data['source_uuid'] ?? null,
            'group_uuid'        => $data['group_uuid'] ?? null,
            'note'              => $data['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'message' => "{$category} recorded"]);
    }

    private function calcDeprStartPeriod(string|\DateTimeInterface $startDate, ?int $cutoffDay = 15): string
    {
        $cutoffDay = $cutoffDay ?: 15;
        $d = Carbon::parse($startDate);
        $addMonths = ($d->day <= $cutoffDay) ? 0 : 1;
        return $d->copy()->startOfMonth()->addMonths($addMonths)->toDateString();
    }


    private function monthsElapsed(string|\DateTimeInterface $startDate, Carbon $period, string $convention): int
    {
        $start = Carbon::parse($startDate)->startOfMonth();
        return max(0, $start->diffInMonths($period));
    }

    private function computeMaxTransfer(string $assetUuid, Carbon $actualDate): array
    {
        $monthStart = $actualDate->copy()->startOfMonth();

        $total = (float) DB::table('assets_value')->where('asset_uuid', $assetUuid)->value('total');

        $lastRow = AssetDeprMonthly::query()
            ->where('asset_uuid', $assetUuid)
            ->whereDate('period', '<', $monthStart)
            ->orderBy('period', 'desc')
            ->first();

        $lastNbv    = $lastRow ? (float) $lastRow->ending_balance : 0.0;
        $lastPeriod = $lastRow?->period;

        $nbvClamped = min($lastNbv, $total);
        $max        = max(0.0, $total - $nbvClamped);

        return [
            'begin_total'        => (float) $total,
            'last_closed_period' => $lastPeriod,
            'last_nbv'           => (float) $lastNbv,
            'max'                => (float) $max,
            'already_out'        => 0.0,        // no longer relevant
            'remaining'          => (float) $max // remaining == max
        ];
    }


    public function carryOverPreview(Request $r)
    {
        $data = $r->validate([
            'from_asset_uuid' => ['required', 'uuid'],
            'actual_date'     => ['required', 'date'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
        ]);

        $info = $this->computeCarryOver(
            $data['from_asset_uuid'],
            Carbon::parse($data['actual_date']),
            (float)$data['amount']
        );

        return response()->json(['ok' => true] + $info);
    }

    private function lastClosedRowBeforeMonth(string $assetUuid, Carbon $monthStart): ?AssetDeprMonthly
    {
        return AssetDeprMonthly::where('asset_uuid', $assetUuid)
            ->whereDate('period', '<', $monthStart)
            ->orderBy('period', 'desc')
            ->first();
    }

    private function grossRemaining(string $assetUuid): float
    {
        return (float) DB::table('assets_value')->where('asset_uuid', $assetUuid)->value('total');
    }

    private function computeCarryOver(string $fromUuid, Carbon $actualDate, float $amount): array
    {
        $monthStart = $actualDate->copy()->startOfMonth();

        $totalGross = (float) DB::table('assets_value')->where('asset_uuid', $fromUuid)->value('total');

        $last = $this->lastClosedRowBeforeMonth($fromUuid, $monthStart);
        $accAsOfLast = (float) ($last->accumulated_depr_end ?? 0);

        $ratio   = ($totalGross > 0) ? ($amount / $totalGross) : 0.0;
        $accMove = round($accAsOfLast * $ratio, 2);
        $nbvMove = max(0.0, $amount - $accMove);

        $capRemaining = $this->grossRemaining($fromUuid);

        return [
            'total_gross'        => $totalGross,
            'accum_as_of_last'   => $accAsOfLast,
            'ratio'              => $ratio,
            'acc_move'           => $accMove,
            'nbv_move'           => $nbvMove,
            'cap_remaining'      => $capRemaining,
            'last_closed_period' => optional($last)->period,
        ];
    }

    public function transferLimit(Request $r)
    {
        $data = $r->validate([
            'from_asset_uuid' => ['required', 'uuid'],
            'actual_date'     => ['required', 'date'],
        ]);

        $cap = $this->computeMaxTransfer($data['from_asset_uuid'], Carbon::parse($data['actual_date']));
        return response()->json(['ok' => true] + $cap);
    }

    public function assetSearch(Request $r)
    {
        $q = trim($r->get('q', ''));
        $rows = Assets::query()
            ->select('uuid', 'asset_code', 'description')
            ->when($q, fn($qq) => $qq->where('asset_code', 'ilike', "%{$q}%")
                ->orWhere('description', 'ilike', "%{$q}%"))
            ->orderBy('asset_code')
            ->get();
        return response()->json(['data' => $rows]);
    }

    private function deriveStartDateFromAV(object $av): ?string
    {
        $d = $av->capitalization_date ?? $av->actual_date ?? $av->in_service_date ?? $av->created_at ?? null;
        return $d ? Carbon::parse($d)->toDateString() : null;
    }

    private function deriveLifeMonthsFromAV(object $av): int
    {
        $life = (int)($av->useful_life_month ?? 0);
        if ($life <= 0 && isset($av->useful_life_months)) $life = (int)$av->useful_life_months;
        if ($life <= 0 && isset($av->useful_life_year))   $life = (int) round(((float)$av->useful_life_year) * 12);
        return $life > 0 ? $life : 60;
    }

    private function componentMonthsYtd(AssetDeprPolicy $policy, Carbon $transferPeriod): int
    {
        $yearStart   = $transferPeriod->copy()->startOfYear();
        $monthBefore = $transferPeriod->copy()->subMonth()->startOfMonth();

        if ($monthBefore->lt($yearStart)) {
            return 0;
        }

        $startDate = Carbon::parse($policy->depr_start_date);
        $cutoff    = $policy->cutoff_day ?? 15;

        $addMonths = ($startDate->day <= $cutoff) ? 0 : 1;
        $eligibleStart = $startDate->copy()->startOfMonth()->addMonths($addMonths);

        $effectiveStart = $eligibleStart->gt($yearStart) ? $eligibleStart : $yearStart;

        if ($monthBefore->lt($effectiveStart)) {
            return 0;
        }

        return $effectiveStart->diffInMonths($monthBefore) + 1;
    }

    // ============================================================
    // applyTransfer() – rewritten based on Excel formulas
    // Rumus 1 (AP col) = tf-val
    // Rumus 2 (AU col) = acq-fix
    // Rumus 3 (AY col) = carry_over_gross_accum
    // ============================================================

    private function applyTransfer(array $data): string
    {
        $type   = $data['transfer_type'] ?? 'tf-val';
        $period = Carbon::parse($data['actual_date'])->startOfMonth();
        $group  = $data['group_uuid'] ?? Str::uuid();

        $fromPolicy = AssetDeprPolicy::where('asset_uuid', $data['from_asset_uuid'])->where('is_active', true)->first();
        $toPolicy   = AssetDeprPolicy::where('asset_uuid', $data['to_asset_uuid'])->where('is_active', true)->first();
        $fromStart  = $this->calcDeprStartPeriod($data['actual_date'], $fromPolicy?->cutoff_day ?? 15);
        $toStart    = $this->calcDeprStartPeriod($data['actual_date'], $toPolicy?->cutoff_day ?? 15);

        // ============================================================
        // RUMUS 1 (AP): tf-val  – transfer nilai gross saja
        // ============================================================
        // Variabel:
        //   UpdateAcquisitionA = AcquisitionA + Transfer
        //   UpdateAcquisitionB = AcquisitionB - Transfer
        //   AD_A = AccumulatedDepreciation bulan sebelum transfer (positif)
        //   AD_B = AccumulatedDepreciation bulan sebelum transfer (positif)
        //   RUL_A = Remaining Usefull Life bulan sebelum transfer
        //   RUL_B = Remaining Usefull Life bulan sebelum transfer
        //
        // Tidak ada AdjustmentDepreciation (AP = 0).
        //
        // Update Depreciation Bulanan (dipakai periode transfer dan seterusnya):
        //   if (RUL_A > 0) DepresiasiBulananA = (UpdateAcquisitionA - AD_A) / RUL_A
        //   else           DepresiasiBulananA = 0
        //   if (RUL_B > 0) DepresiasiBulananB = (UpdateAcquisitionB - AD_B) / RUL_B
        //   else           DepresiasiBulananB = 0
        //
        // Update Acquisition:
        //   UpdateAcquisitionA = AcquisitionA + Transfer  → update assets_value A
        //   UpdateAcquisitionB = AcquisitionB - Transfer  → update assets_value B
        //
        // Update Price & PPN (jika ada):
        //   if VatInA: VatInA = transfer*11%; NewPrice = transfer - VatInA + OldPriceA; NewVatInA = VatInOldA + VatInA
        //   else:      NewPriceA = PriceA + Transfer
        //   if VatInB: VatInB = transfer*11%; NewPriceB = transfer - VatInB - OldPriceB; NewVatInB = VatInOldB - VatInB
        //   else:      NewPriceB = PriceB - Transfer
        // ============================================================
        if ($type === 'tf-val') {

            $transfer = (float) $data['amount'];

            // Ambil data prev month untuk A dan B
            $prevA = $this->lastClosedRowBeforeMonth($data['to_asset_uuid'], $period);   // A = penerima
            $prevB = $this->lastClosedRowBeforeMonth($data['from_asset_uuid'], $period); // B = pemberi

            $adA  = (float) ($prevA?->accumulated_depr_end ?? 0); // AD_A positif
            $adB  = (float) ($prevB?->accumulated_depr_end ?? 0); // AD_B positif
            $rulA = (float) ($prevA?->remaining_useful_life_months
                ?? $toPolicy?->useful_life_months
                ?? 0);
            $rulB = (float) ($prevB?->remaining_useful_life_months
                ?? $fromPolicy?->useful_life_months
                ?? 0);

            // Gross acquisition saat ini
            $acqA = (float) DB::table('assets_value')->where('asset_uuid', $data['to_asset_uuid'])->value('total');
            $acqB = (float) DB::table('assets_value')->where('asset_uuid', $data['from_asset_uuid'])->value('total');

            $updateAcqA = $acqA + $transfer;
            $updateAcqB = $acqB - $transfer;

            // Depreciation bulanan baru (dihitung, disimpan ke policy/ledger di processMonthlyDepr)
            $deprBulananA = ($rulA > 0) ? ($updateAcqA - $adA) / $rulA : 0.0;
            $deprBulananB = ($rulB > 0) ? ($updateAcqB - $adB) / $rulB : 0.0;

            DB::transaction(function () use ($data, $period, $group, $fromStart, $toStart, $transfer) {
                // Gross OUT dari B (pemberi)
                AssetDeprMovement::create([
                    'asset_uuid'        => $data['from_asset_uuid'],
                    'period'            => $period,
                    'category'          => AssetDeprMovement::TRANSFER_OUT,
                    'amount'            => $transfer,
                    'depr_start_period' => $fromStart,
                    'group_uuid'        => $group,
                    'source_type'       => $data['source_type'] ?? 'manual',
                    'source_uuid'       => $data['source_uuid'] ?? null,
                    'note'              => $data['note'] ?? null,
                ]);

                // Gross IN ke A (penerima)
                AssetDeprMovement::create([
                    'asset_uuid'        => $data['to_asset_uuid'],
                    'period'            => $period,
                    'category'          => AssetDeprMovement::TRANSFER_IN,
                    'amount'            => $transfer,
                    'depr_start_period' => $toStart,
                    'group_uuid'        => $group,
                    'source_type'       => $data['source_type'] ?? 'manual',
                    'source_uuid'       => $data['source_uuid'] ?? null,
                    'note'              => $data['note'] ?? null,
                ]);

                // Tidak ada AdjustmentDepreciation untuk tf-val (AP = 0)

                // Update Acquisition di assets_value
                DB::table('assets_value')
                    ->where('asset_uuid', $data['to_asset_uuid'])
                    ->increment('total', $transfer);

                DB::table('assets_value')
                    ->where('asset_uuid', $data['from_asset_uuid'])
                    ->decrement('total', $transfer);
            });

            return 'Transfer (tf-val) recorded';
        }


        // ============================================================
        // RUMUS 2 (AU): acq-fix  – transfer nilai + koreksi accumulated depreciation
        // ============================================================
        // Variabel:
        //   A = asset penerima (to_asset), B = asset pemberi (from_asset)
        //   UL_A = useful life A,  UL_B = useful life B
        //   RUL_A = remaining useful life A bulan sebelumnya (prev month)
        //   RUL_B = remaining useful life B bulan sebelumnya (prev month)
        //   X = UL_A - RUL_A
        //   Y = UL_B - RUL_B
        //   gaplife = X - Y
        //
        // if gaplife >= 0:
        //   AdjMonthA   = UL_A - gaplife          (= AO_A + Y)
        //   CurrentUL_B = UL_B
        //   CurrentY    = Y
        //   CurrentGapLife = gaplife
        // else:
        //   CurrentUL_B    = UL_B + gaplife
        //   CurrentY       = CurrentUL_B - RUL_B
        //   CurrentGapLife = X - CurrentY
        //   AdjMonthA      = UL_A - CurrentGapLife
        //
        // AdjDepresiasiA = (Transfer / AdjMonthA) * (X - CurrentGapLife)
        //   → positif: menambah accumulated A  [PHP: amount = +adjA]
        // AdjDepresiasiB = -(Transfer / CurrentUL_B) * CurrentY
        //   → negatif: mengurangi accumulated B [PHP: amount = adjB (negatif)]
        //
        // Depreciation Bulanan baru:
        //   if RUL_A > 0: DepresiasiBulananA = (Transfer / AdjMonthA) + (AcquisitionA / UL_A)
        //   else:         DepresiasiBulananA = 0
        //   if RUL_B > 0: DepresiasiBulananB = UpdateAcquisitionB / CurrentUL_B
        //   else:         DepresiasiBulananB = 0
        //
        // Update Acquisition:
        //   UpdateAcquisitionA = AcquisitionA + Transfer
        //   UpdateAcquisitionB = AcquisitionB - Transfer
        // ============================================================
        if ($type === self::TRANSFER_TYPE_ACQ_FIX) {

            $transfer = (float) $data['amount'];

            if (!$toPolicy || !$fromPolicy) {
                throw ValidationException::withMessages([
                    'from_asset_uuid' => 'Both assets must have active depreciation policies for acq-fix.',
                ]);
            }

            $ulA = (int) ($toPolicy->useful_life_months   ?: 60);
            $ulB = (int) ($fromPolicy->useful_life_months ?: 60);

            // RUL dari ledger bulan sebelumnya
            $rulA = $this->remainingUsefulLifePrevMonth($data['to_asset_uuid'],   $toPolicy,   $period);
            $rulB = $this->remainingUsefulLifePrevMonth($data['from_asset_uuid'], $fromPolicy, $period);

            // Expired life
            $X = $ulA - $rulA;
            $Y = $ulB - $rulB;
            $gaplife = $X - $Y;

            if ($gaplife >= 0) {
                $adjMonthA      = $ulA - $gaplife;   // = RUL_A + Y
                $currentULB     = $ulB;
                $currentY       = $Y;
                $currentGapLife = $gaplife;
            } else {
                $currentULB     = $ulB + $gaplife;
                $currentY       = $currentULB - $rulB;
                $currentGapLife = $X - $currentY;
                $adjMonthA      = $ulA - $currentGapLife;
            }

            if ($adjMonthA <= 0 || $currentULB <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Invalid acq-fix: AdjMonthA atau CurrentULB <= 0.',
                ]);
            }

            // Adj depreciation
            $adjDeprA = ($transfer / $adjMonthA) * ($X - $currentGapLife);  // positif → tambah accum A
            $adjDeprB = - ($transfer / $currentULB) * $currentY;             // negatif → kurangi accum B

            // Acquisisi terbaru
            $acqA = (float) DB::table('assets_value')->where('asset_uuid', $data['to_asset_uuid'])->value('total');

            DB::transaction(function () use (
                $data,
                $period,
                $group,
                $fromStart,
                $toStart,
                $transfer,
                $adjDeprA,
                $adjDeprB
            ) {
                // Gross OUT dari B
                AssetDeprMovement::create([
                    'asset_uuid'        => $data['from_asset_uuid'],
                    'period'            => $period,
                    'category'          => AssetDeprMovement::TRANSFER_OUT,
                    'amount'            => $transfer,
                    'depr_start_period' => $fromStart,
                    'group_uuid'        => $group,
                    'source_type'       => $data['source_type'] ?? 'manual',
                    'source_uuid'       => $data['source_uuid'] ?? null,
                    'note'              => trim(($data['note'] ?? '') . ' | acq-fix gross OUT'),
                ]);

                // Gross IN ke A
                AssetDeprMovement::create([
                    'asset_uuid'        => $data['to_asset_uuid'],
                    'period'            => $period,
                    'category'          => AssetDeprMovement::TRANSFER_IN,
                    'amount'            => $transfer,
                    'depr_start_period' => $toStart,
                    'group_uuid'        => $group,
                    'source_type'       => $data['source_type'] ?? 'manual',
                    'source_uuid'       => $data['source_uuid'] ?? null,
                    'note'              => trim(($data['note'] ?? '') . ' | acq-fix gross IN'),
                ]);

                // AdjDepresiasiA → tambah accumulated A (positif)
                if (abs($adjDeprA) > 0.001) {
                    AssetDeprMovement::create([
                        'asset_uuid'        => $data['to_asset_uuid'],
                        'period'            => $period,
                        'category'          => AssetDeprMovement::ADJUSTMENT_DEPRECIATION,
                        'amount'            => round($adjDeprA, 2),
                        'depr_start_period' => $period,
                        'group_uuid'        => $group,
                        'source_type'       => $data['source_type'] ?? 'manual',
                        'source_uuid'       => $data['source_uuid'] ?? null,
                        'note'              => trim(($data['note'] ?? '') . ' | acq-fix adj accum A'),
                    ]);
                }

                // AdjDepresiasiB → kurangi accumulated B (negatif)
                if (abs($adjDeprB) > 0.001) {
                    AssetDeprMovement::create([
                        'asset_uuid'        => $data['from_asset_uuid'],
                        'period'            => $period,
                        'category'          => AssetDeprMovement::ADJUSTMENT_DEPRECIATION,
                        'amount'            => round($adjDeprB, 2),
                        'depr_start_period' => $period,
                        'group_uuid'        => $group,
                        'source_type'       => $data['source_type'] ?? 'manual',
                        'source_uuid'       => $data['source_uuid'] ?? null,
                        'note'              => trim(($data['note'] ?? '') . ' | acq-fix adj accum B'),
                    ]);
                }

                // Update Acquisition di assets_value
                DB::table('assets_value')
                    ->where('asset_uuid', $data['to_asset_uuid'])
                    ->increment('total', $transfer);

                DB::table('assets_value')
                    ->where('asset_uuid', $data['from_asset_uuid'])
                    ->decrement('total', $transfer);
            });

            return 'Acquisition-fix transfer recorded';
        }


        // ============================================================
        // RUMUS 3 (AY): carry_over_gross_accum
        // ============================================================
        // Variabel:
        //   A = asset penerima (to_asset), B = asset pemberi (from_asset)
        //   UL_A, UL_B = useful life (months)
        //   RUL_A_DesLastYear = remaining useful life akhir Desember tahun lalu  (= H col / field di policy/yearly)
        //   RUL_A_LastMonth   = remaining useful life bulan sebelum transfer
        //   RUL_B_DesLastYear = idem untuk B
        //   RUL_B_LastMonth   = idem untuk B
        //   AD_A = AccumulatedDepreciation akhir Desember tahun lalu (positif)
        //   AD_B = idem untuk B
        //   X = RUL_A_DesLastYear - RUL_A_LastMonth   (expired months A dalam tahun berjalan)
        //   Y = RUL_B_DesLastYear - RUL_B_LastMonth   (expired months B dalam tahun berjalan)
        //
        // Update Acquisition:
        //   UpdateAcquisitionA = AcquisitionA + Transfer
        //   UpdateAcquisitionB = AcquisitionB - Transfer
        //
        // Update Depreciation Bulanan:
        //   if RUL_A_LastMonth > 0: DepresiasiBulananA = (UpdateAcquisitionA - AD_A) / RUL_A_LastMonth
        //   else:                   DepresiasiBulananA = 0
        //   if RUL_B_LastMonth > 0: DepresiasiBulananB = (UpdateAcquisitionB - AD_B) / RUL_B_LastMonth
        //   else:                   DepresiasiBulananB = 0
        //
        // Update Adjustment Depreciation:
        //   AdjDepresiasiA = DepresiasiBulananA * X    → positif: tambah accum A
        //   AdjDepresiasiB = -DepresiasiBulananB * Y   → negatif: kurangi accum B
        // ============================================================
        if ($type === self::TRANSFER_TYPE_CARRY_OVER) {

            $transfer = (float) $data['amount'];

            // RUL bulan sebelum transfer
            $prevA = $this->lastClosedRowBeforeMonth($data['to_asset_uuid'],   $period);
            $prevB = $this->lastClosedRowBeforeMonth($data['from_asset_uuid'], $period);

            $rulALastMonth = (float) ($prevA?->remaining_useful_life_months ?? $toPolicy?->useful_life_months   ?? 0);
            $rulBLastMonth = (float) ($prevB?->remaining_useful_life_months ?? $fromPolicy?->useful_life_months ?? 0);

            // AD = accumulated depreciation akhir Desember tahun lalu (dari yearly closing)
            $transferYear = (int) $period->year;
            $prevYearA = AssetDeprYearly::where('asset_uuid', $data['to_asset_uuid'])
                ->where('fiscal_year', $transferYear - 1)
                ->first();
            $prevYearB = AssetDeprYearly::where('asset_uuid', $data['from_asset_uuid'])
                ->where('fiscal_year', $transferYear - 1)
                ->first();

            $adA = (float) ($prevYearA?->accumulated_depr_end ?? 0); // positif
            $adB = (float) ($prevYearB?->accumulated_depr_end ?? 0); // positif

            // RUL akhir Desember tahun lalu
            // Didapat dari: useful_life - elapsed_months_at_end_of_prev_year
            // Atau bisa disimpan langsung; gunakan field remaining di yearly jika ada,
            // atau hitung dari policy + capitalization_date.
            // Untuk sementara hitung dari selisih useful_life - bulan_terpakai_sampai_Desember_lalu.
            $rulADesLastYear = $this->remainingUsefulLifeAtYearEnd($data['to_asset_uuid'],   $toPolicy,   $transferYear - 1);
            $rulBDesLastYear = $this->remainingUsefulLifeAtYearEnd($data['from_asset_uuid'], $fromPolicy, $transferYear - 1);

            // X dan Y = expired months dalam tahun berjalan s/d bulan sebelum transfer
            $X = $rulADesLastYear - $rulALastMonth;
            $Y = $rulBDesLastYear - $rulBLastMonth;

            // Gross acquisition saat ini
            $acqA = (float) DB::table('assets_value')->where('asset_uuid', $data['to_asset_uuid'])->value('total');
            $acqB = (float) DB::table('assets_value')->where('asset_uuid', $data['from_asset_uuid'])->value('total');

            $updateAcqA = $acqA + $transfer;
            $updateAcqB = $acqB - $transfer;

            // Depreciation bulanan baru
            $deprBulananA = ($rulALastMonth > 0) ? ($updateAcqA - $adA) / $rulALastMonth : 0.0;
            $deprBulananB = ($rulBLastMonth > 0) ? ($updateAcqB - $adB) / $rulBLastMonth : 0.0;

            // Adjustment depreciation
            $adjDeprA =  $deprBulananA * $X;   // positif → tambah accum A
            $adjDeprB = -$deprBulananB * $Y;   // negatif → kurangi accum B

            DB::transaction(function () use (
                $data,
                $period,
                $group,
                $fromStart,
                $toStart,
                $transfer,
                $adjDeprA,
                $adjDeprB
            ) {
                // Gross OUT dari B
                AssetDeprMovement::create([
                    'asset_uuid'        => $data['from_asset_uuid'],
                    'period'            => $period,
                    'category'          => AssetDeprMovement::TRANSFER_OUT,
                    'amount'            => $transfer,
                    'depr_start_period' => $fromStart,
                    'group_uuid'        => $group,
                    'source_type'       => $data['source_type'] ?? 'manual',
                    'source_uuid'       => $data['source_uuid'] ?? null,
                    'note'              => trim(($data['note'] ?? '') . ' | carry-over gross OUT'),
                ]);

                // Gross IN ke A
                AssetDeprMovement::create([
                    'asset_uuid'        => $data['to_asset_uuid'],
                    'period'            => $period,
                    'category'          => AssetDeprMovement::TRANSFER_IN,
                    'amount'            => $transfer,
                    'depr_start_period' => $toStart,
                    'group_uuid'        => $group,
                    'source_type'       => $data['source_type'] ?? 'manual',
                    'source_uuid'       => $data['source_uuid'] ?? null,
                    'note'              => trim(($data['note'] ?? '') . ' | carry-over gross IN'),
                ]);

                // AdjDepresiasiA → tambah accumulated A
                if (abs($adjDeprA) > 0.001) {
                    AssetDeprMovement::create([
                        'asset_uuid'        => $data['to_asset_uuid'],
                        'period'            => $period,
                        'category'          => AssetDeprMovement::ADJUSTMENT_DEPRECIATION,
                        'amount'            => round($adjDeprA, 2),
                        'depr_start_period' => $toStart,
                        'group_uuid'        => $group,
                        'source_type'       => $data['source_type'] ?? 'manual',
                        'source_uuid'       => $data['source_uuid'] ?? null,
                        'note'              => trim(($data['note'] ?? '') . ' | carry-over adj accum A'),
                    ]);
                }

                // AdjDepresiasiB → kurangi accumulated B
                if (abs($adjDeprB) > 0.001) {
                    AssetDeprMovement::create([
                        'asset_uuid'        => $data['from_asset_uuid'],
                        'period'            => $period,
                        'category'          => AssetDeprMovement::ADJUSTMENT_DEPRECIATION,
                        'amount'            => round($adjDeprB, 2),
                        'depr_start_period' => $fromStart,
                        'group_uuid'        => $group,
                        'source_type'       => $data['source_type'] ?? 'manual',
                        'source_uuid'       => $data['source_uuid'] ?? null,
                        'note'              => trim(($data['note'] ?? '') . ' | carry-over adj accum B'),
                    ]);
                }

                // Update Acquisition di assets_value
                DB::table('assets_value')
                    ->where('asset_uuid', $data['to_asset_uuid'])
                    ->increment('total', $transfer);

                DB::table('assets_value')
                    ->where('asset_uuid', $data['from_asset_uuid'])
                    ->decrement('total', $transfer);
            });

            return 'Carry-Over transfer recorded';
        }

        throw new \InvalidArgumentException("Unknown transfer type: {$type}");
    }

    /* ============================================================
     *  TRANSFER REQUESTS
     * ============================================================
     */

    public function transferRequestsIndex()
    {
        abort_unless($this->canReadTransferRequests(), 403);
        return view('depreciation.transfer_requests');
    }
    public function dtTransferRequests(Request $r)
    {
        if (!$this->canReadTransferRequests()) {
            return DataTables::of(collect())->toJson();
        }

        $q = AssetDeprTransferRequest::query()
            ->with([
                'fromAsset:uuid,asset_code,description',
                'toAsset:uuid,asset_code,description',
            ]);

        // type
        if ($type = $r->get('type')) {
            $q->where('transfer_type', $type);
        }

        // status approval
        if ($status = $r->get('status')) {
            $q->where('kode_status', $status);
        }

        // requester
        if ($req = $r->get('requester')) {
            $q->where('requested_by', $req);
        }

        // request date range (created_at)
        if ($from = $r->get('req_from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $r->get('req_to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        // approve date range (approved_at)
        if ($from = $r->get('apr_from')) {
            $q->whereDate('approved_at', '>=', $from);
        }
        if ($to = $r->get('apr_to')) {
            $q->whereDate('approved_at', '<=', $to);
        }

        if ($search = trim($r->get('asset_q', ''))) {
            $q->where(function ($qq) use ($search) {
                $qq->whereHas('fromAsset', function ($qa) use ($search) {
                    $qa->where('asset_code', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%");
                })->orWhereHas('toAsset', function ($qa) use ($search) {
                    $qa->where('asset_code', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%");
                });
            });
        }
        $dt = DataTables::eloquent($q)
            ->addColumn('from_uuid', fn($row) => $row->fromAsset?->uuid)
            ->addColumn('from_code', fn($row) => $row->fromAsset?->asset_code)
            ->addColumn('from_name', fn($row) => $row->fromAsset?->description)
            ->addColumn('to_uuid',   fn($row) => $row->toAsset?->uuid)
            ->addColumn('to_code',   fn($row) => $row->toAsset?->asset_code)
            ->addColumn('to_name',   fn($row) => $row->toAsset?->description)
            ->addColumn('attachment_url', fn($row) => $row->attachment_path ? Storage::url($row->attachment_path) : null);

        $dt->filterColumn('from_asset_uuid', function ($query, $keyword) {
            $k = trim((string)$keyword);
            if ($k === '') return;
            $query->whereHas('fromAsset', function ($qa) use ($k) {
                $qa->where('asset_code', 'ilike', "%{$k}%")
                    ->orWhere('description', 'ilike', "%{$k}%");
            });
        });

        $dt->filterColumn('to_asset_uuid', function ($query, $keyword) {
            $k = trim((string)$keyword);
            if ($k === '') return;
            $query->whereHas('toAsset', function ($qa) use ($k) {
                $qa->where('asset_code', 'ilike', "%{$k}%")
                    ->orWhere('description', 'ilike', "%{$k}%");
            });
        });

        return $dt->toJson();
    }


    public function storeTransferRequest(Request $r)
    {
        abort_unless($this->canCreateTransferRequests(), 403);
        $data = $r->validate([
            'transfer_type'   => ['nullable', 'in:tf-val,' . self::TRANSFER_TYPE_CARRY_OVER . ',' . self::TRANSFER_TYPE_ACQ_FIX],
            'from_asset_uuid' => ['required', 'uuid'],
            'to_asset_uuid'   => ['required', 'uuid', 'different:from_asset_uuid'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'actual_date'     => ['required', 'date'],
            'note'            => ['nullable', 'string', 'max:300'],
            'attachment'      => ['nullable', 'file', 'max:5120'],
        ]);
        $this->assertActualDateIsCurrentMonth($data['actual_date'], 'Add New');
        $this->assertAssetsHaveAcquisition($data['from_asset_uuid'], $data['to_asset_uuid']);


        $type = $data['transfer_type'] ?? 'tf-val';
        $uid = auth()->user()?->name;
        abort_if(!$uid, 401, 'No session UID.');

        if ($type === 'tf-val') {
            $cap = $this->computeMaxTransfer($data['from_asset_uuid'], Carbon::parse($data['actual_date']));
            if ((float)$data['amount'] > $cap['remaining']) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount exceeds max allowed for that month (tf-val).',
                ]);
            }
        }

        $path = null;
        if ($r->hasFile('attachment')) {
            $path = $r->file('attachment')->store('depr_transfer_attachments', 'public');
        }

        $now    = Carbon::now();
        $prefix = 'TRF' . $now->format('ym');

        $last = AssetDeprTransferRequest::where('transfer_code', 'like', $prefix . '%')
            ->orderBy('transfer_code', 'desc')
            ->first();

        if ($last) {
            $lastSeq = (int) substr($last->transfer_code, -4);
            $seq     = $lastSeq + 1;
        } else {
            $seq = 1;
        }

        $code = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);


        $req = AssetDeprTransferRequest::create([
            'from_asset_uuid' => $data['from_asset_uuid'],
            'to_asset_uuid'   => $data['to_asset_uuid'],
            'transfer_type'   => $type,
            'amount'          => $data['amount'],
            'actual_date'     => $data['actual_date'],
            'note'            => $data['note'] ?? null,
            'attachment_path' => $path,
            'kode_status'     => self::STATUS_APR,
            'requested_by'    => $uid,
            'group_uuid'      => Str::uuid(),
            'transfer_code'   => $code,
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Transfer request saved. Waiting for approval.',
            'uuid'    => (string) $req->uuid,
        ]);
    }

    public function approveTransferRequest(Request $r, string $uuid)
    {
        abort_unless($this->canApproveTransferRequests(), 403);
        $req = AssetDeprTransferRequest::where('uuid', $uuid)
            ->where('kode_status', self::STATUS_APR)
            ->firstOrFail();

        $uid = auth()->user()?->name;
        abort_if(!$uid, 401, 'No session UID.');

        $this->assertAssetsHaveAcquisition($req->from_asset_uuid, $req->to_asset_uuid);

        DB::transaction(function () use ($req, $uid) {
            $this->applyTransfer([
                'transfer_type'   => $req->transfer_type,
                'from_asset_uuid' => $req->from_asset_uuid,
                'to_asset_uuid'   => $req->to_asset_uuid,
                'amount'          => (float) $req->amount,
                'actual_date'     => $req->actual_date->toDateString(),
                'note'            => $req->note,
                'source_type'     => 'depr_transfer_request',
                'source_uuid'     => $req->uuid,
                'group_uuid'      => $req->group_uuid,
            ]);

            $req->kode_status = self::STATUS_ACC;
            $req->approved_by = $uid;
            $req->approved_at = now();
            $req->save();
        });

        // runmonth to change immediately the table
        $period = Carbon::parse($req->actual_date)->startOfMonth();
        $this->processMonthlyDepr($period);

        return response()->json([
            'ok'      => true,
            'message' => 'Transfer approved, posted into depreciation, and month recomputed.',
        ]);
    }

    public function rejectTransferRequest(Request $r, string $uuid)
    {
        abort_unless($this->canApproveTransferRequests(), 403);
        $data = $r->validate([
            'status_note' => ['nullable', 'string', 'max:500'],
        ]);

        $uid = auth()->user()?->name;
        abort_if(!$uid, 401, 'No session UID.');

        $req = AssetDeprTransferRequest::where('uuid', $uuid)
            ->where('kode_status', self::STATUS_APR)
            ->firstOrFail();

        $req->kode_status = self::STATUS_REJ;
        $req->approved_by = $uid;
        $req->approved_at = now();
        if (!empty($data['status_note'])) {
            $req->note = trim(($req->note ? $req->note . ' | ' : '') . 'REJ: ' . $data['status_note']);
        }
        $req->save();

        return response()->json([
            'ok'      => true,
            'message' => 'Transfer rejected.',
        ]);
    }
    public function destroyTransferRequest(string $uuid)
    {
        abort_unless($this->canDeleteTransferRequests(), 403);

        $req = AssetDeprTransferRequest::where('uuid', $uuid)->firstOrFail();

        $period = Carbon::parse($req->actual_date)->startOfMonth();

        try {
            // Kalau sudah ACC, berarti sudah posting ke movement, assets_value, ledger.
            // Jadi harus rollback efek posting-nya.
            if ($req->kode_status === self::STATUS_ACC) {
                $this->guardWritablePeriod($period, 'deleteTransferRequest', [
                    'require_prev_month' => true,
                    'enforce_first_run_min_eligible' => false,
                ]);
            }

            DB::transaction(function () use ($req) {
                if ($req->kode_status === self::STATUS_ACC) {
                    $amount = (float) $req->amount;

                    // 1. Balikin assets_value karena waktu approve applyTransfer()
                    //    increment total di asset penerima dan decrement total di asset pemberi.
                    DB::table('assets_value')
                        ->where('asset_uuid', $req->to_asset_uuid)
                        ->decrement('total', $amount);

                    DB::table('assets_value')
                        ->where('asset_uuid', $req->from_asset_uuid)
                        ->increment('total', $amount);

                    // 2. Hapus movement hasil posting request ini.
                    //    Ini yang bikin transfers_in/out masih kebaca di ledger.
                    AssetDeprMovement::query()
                        ->where('source_type', 'depr_transfer_request')
                        ->where('source_uuid', $req->uuid)
                        ->delete();
                }

                // 3. Soft delete request-nya.
                $req->delete();
            });

            // 4. Recalculate dari bulan request sampai latest period,
            //    karena opening balance bulan berikutnya ikut terpengaruh.
            if ($req->kode_status === self::STATUS_ACC) {
                $this->reprocessDepreciationFrom($period);
            }

            return response()->json([
                'ok'      => true,
                'message' => 'Transfer request deleted and depreciation recalculated.',
            ]);
        } catch (\Throwable $e) {
            if ($resp = $this->renderGuardError($e)) return $resp;

            report($e);

            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage() ?: 'Failed to delete transfer request.',
            ], 500);
        }
    }
    public function downloadTransferRequestAttachment(string $uuid)
    {
        abort_unless($this->canReadTransferRequests(), 403);
        $req = AssetDeprTransferRequest::where('uuid', $uuid)->firstOrFail();

        if (!$req->attachment_path || !Storage::disk('public')->exists($req->attachment_path)) {
            abort(404, 'Attachment not found.');
        }

        return Storage::disk('public')->download(
            $req->attachment_path,
            basename($req->attachment_path)
        );
    }
    public function updateTransferRequest(Request $r, string $uuid)
    {
        abort_unless($this->canUpdateTransferRequests(), 403);
        $req = AssetDeprTransferRequest::where('uuid', $uuid)->firstOrFail();

        if ($req->kode_status !== self::STATUS_APR) {
            return response()->json([
                'ok'      => false,
                'message' => 'Only APR (waiting approval) requests can be edited.',
            ], 422);
        }

        $data = $r->validate([
            'transfer_type' => ['nullable', 'in:tf-val,' . self::TRANSFER_TYPE_CARRY_OVER . ',' . self::TRANSFER_TYPE_ACQ_FIX],
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'actual_date'   => ['required', 'date'],
            'note'          => ['nullable', 'string', 'max:300'],
            'attachment'    => ['nullable', 'file', 'max:5120'],
        ]);
        $this->assertActualDateIsCurrentMonth($data['actual_date'], 'Edit');

        $type = $data['transfer_type'] ?? $req->transfer_type;

        if ($type === 'tf-val') {
            $cap = $this->computeMaxTransfer(
                $req->from_asset_uuid,
                Carbon::parse($data['actual_date'])
            );

            if ((float) $data['amount'] > $cap['remaining']) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount exceeds max allowed for that month (tf-val).',
                ]);
            }
        }

        $path = $req->attachment_path;
        if ($r->hasFile('attachment')) {
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
            $path = $r->file('attachment')->store('depr_transfer_attachments', 'public');
        }

        $req->transfer_type   = $type;
        $req->amount          = $data['amount'];
        $req->actual_date     = $data['actual_date'];
        $req->note            = $data['note'] ?? null;
        $req->attachment_path = $path;
        $req->save();

        return response()->json([
            'ok'      => true,
            'message' => 'Transfer request updated.',
        ]);
    }
    protected function canReadTransferRequests(): bool
    {
        $u = auth()->user();
        return $u && $u->hasAction('TRANSFER', 'R');
    }

    protected function canCreateTransferRequests(): bool
    {
        $u = auth()->user();
        return $u && $u->hasAction('TRANSFER', 'C');
    }

    protected function canUpdateTransferRequests(): bool
    {
        $u = auth()->user();
        return $u && $u->hasAction('TRANSFER', 'U');
    }

    protected function canApproveTransferRequests(): bool
    {
        $u = auth()->user();
        return $u && $u->hasAction('TRANSFER', 'APR');
    }

    protected function canDeleteTransferRequests(): bool
    {
        $u = auth()->user();
        return $u && $u->hasAction('TRANSFER', 'D');
    }
    private function assertAssetsHaveAcquisition(string $fromUuid, string $toUuid): void
    {
        $rows = DB::table('assets_value')
            ->select('asset_uuid', 'capitalization_date')
            ->whereIn('asset_uuid', [$fromUuid, $toUuid])
            ->get()
            ->keyBy('asset_uuid');

        $errors = [];

        foreach (
            [
                'from_asset_uuid' => $fromUuid,
                'to_asset_uuid'   => $toUuid,
            ] as $field => $uuid
        ) {

            $av = $rows->get($uuid);

            if (!$av) {
                $errors[$field] = 'Asset belum punya assets_value (belum dilakukan acquisition).';
                continue;
            }

            if (empty($av->capitalization_date)) {
                $errors[$field] = 'Asset belum punya capitalization_date (belum dilakukan acquisition).';
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertActualDateIsCurrentMonth(string|\DateTimeInterface $actualDate, string $context = 'transfer_request'): void
    {
        $d = Carbon::parse($actualDate)->startOfDay();

        $start = now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
        $end   = now()->endOfMonth()->endOfDay();

        if ($d->lt($start) || $d->gt($end)) {
            throw ValidationException::withMessages([
                'actual_date' => "Actual Date for {$context} must be within {$start->isoFormat('MMMM YYYY')} - {$end->isoFormat('MMMM YYYY')}.",
            ]);
        }
    }
    private function remainingUsefulLifePrevMonth(string $assetUuid, AssetDeprPolicy $policy, Carbon $transferPeriod): int
    {
        $life = (int) ($policy->useful_life_months ?: 60);
        if ($life <= 0) $life = 60;

        $prev = $transferPeriod->copy()->subMonth()->startOfMonth();

        // wajib ada row ledger bulan sebelumnya untuk asset tsb (tetap rule kamu)
        $row = AssetDeprMonthly::query()
            ->where('asset_uuid', $assetUuid)
            ->whereDate('period', $prev->toDateString())
            ->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'actual_date' => "acq-fix butuh ledger bulan sebelumnya. Jalankan Process Depreciation Month untuk {$prev->format('Y-m')} dulu.",
            ]);
        }

        // eligibleStart mengikuti cutoff & depr_start_date
        $startDate = $policy->depr_start_date ? Carbon::parse($policy->depr_start_date) : null;
        $cutoff    = (int) ($policy->cutoff_day ?: 15);

        // kalau depr_start_date null, anggap belum eligible -> RUL=life
        if (! $startDate) {
            return $life;
        }

        $eligibleStart = $startDate->copy()->startOfMonth()->addMonths(
            ($startDate->day <= $cutoff) ? 0 : 1
        );

        // kalau prev < eligibleStart -> belum mulai eligible
        if ($prev->lt($eligibleStart)) {
            return $life;
        }

        // =====================================================
        // FIX: used months = jumlah bulan "posted" (bukan diffInMonths)
        // =====================================================
        $used = (int) DB::table('assets_depr_ledger_monthly as m')
            ->where('m.asset_uuid', $assetUuid)
            ->whereDate('m.period', '>=', $eligibleStart->toDateString())
            ->whereDate('m.period', '<=', $prev->toDateString())
            ->where(function ($q) {
                $q->whereRaw('COALESCE(m.depr_expense,0) <> 0')
                    ->orWhereRaw('COALESCE(m.adjustment_depreciation,0) <> 0')
                    ->orWhereRaw('COALESCE(m.accumulated_depr_end,0) <> 0');
            })
            ->count();

        return max(0, $life - $used);
    }
    private function remainingUsefulLifeAtYearEnd(string $assetUuid, ?AssetDeprPolicy $policy, int $year): int
    {
        if (!$policy) return 0;

        $life = (int) ($policy->useful_life_months ?: 60);
        if ($life <= 0) $life = 60;

        $dec = Carbon::create($year, 12, 1)->startOfMonth();

        $startDate = $policy->depr_start_date ? Carbon::parse($policy->depr_start_date) : null;
        if (! $startDate) {
            return $life;
        }

        $cutoff = (int) ($policy->cutoff_day ?: 15);
        $eligibleStart = $startDate->copy()->startOfMonth()->addMonths(
            ($startDate->day <= $cutoff) ? 0 : 1
        );

        if ($dec->lt($eligibleStart)) {
            return $life;
        }

        // cari bulan ledger terakhir yang sudah ada row (<= Dec)
        $lastLedgerPeriod = DB::table('assets_depr_ledger_monthly')
            ->where('asset_uuid', $assetUuid)
            ->whereDate('period', '<=', $dec->toDateString())
            ->max('period');

        // kalau belum ada ledger sama sekali sampai Dec, berarti belum pernah posting -> life
        if (! $lastLedgerPeriod) {
            return $life;
        }

        $until = Carbon::parse($lastLedgerPeriod)->startOfMonth();
        if ($until->lt($eligibleStart)) {
            return $life;
        }

        // =====================================================
        // FIX: used months = jumlah bulan "posted" sampai bulan terakhir ledger
        // =====================================================
        $used = (int) DB::table('assets_depr_ledger_monthly as m')
            ->where('m.asset_uuid', $assetUuid)
            ->whereDate('m.period', '>=', $eligibleStart->toDateString())
            ->whereDate('m.period', '<=', $until->toDateString())
            ->where(function ($q) {
                $q->whereRaw('COALESCE(m.depr_expense,0) <> 0')
                    ->orWhereRaw('COALESCE(m.adjustment_depreciation,0) <> 0')
                    ->orWhereRaw('COALESCE(m.accumulated_depr_end,0) <> 0');
            })
            ->count();

        return max(0, $life - $used);
    }
}
