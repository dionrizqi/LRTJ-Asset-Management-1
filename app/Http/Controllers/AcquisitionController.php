<?php

namespace App\Http\Controllers;

use App\Models\AssetsValueHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Yajra\DataTables\Facades\DataTables;
use App\Models\AssetDeprPolicy;
use App\Models\AssetDeprMonthly;
use App\Models\AssetDeprMovement;

class AcquisitionController extends Controller
{
    public function index()
    {
        return view('acquisition.acquisition');
    }
    public function dtGlobal(Request $request)
    {
        if (!$this->canReadAcquisition()) {
            return DataTables::of(collect())->toJson();
        }

        $user      = $request->user();
        $canDelete = $user && $user->hasAction('ACQUISITION', 'D');

        $pic       = $request->input('pic');
        $pajak     = $request->input('pajak');
        $capFrom   = $request->input('cap_from');
        $capTo     = $request->input('cap_to');
        $assetLike = $request->input('asset');

        // 1) Subquery: latest created_at per asset
        $latestPerAsset = DB::table('assets_value_history')
            ->select(
                'asset_uuid',
                DB::raw('MAX(created_at) AS max_created_at')
            )
            ->whereNull('deleted_at')
            ->groupBy('asset_uuid');

        // 2) Join main history with that subquery
        $q = DB::table('assets_value_history as h')
            ->joinSub($latestPerAsset, 'lh', function ($join) {
                $join->on('h.asset_uuid', '=', 'lh.asset_uuid')
                    ->on('h.created_at', '=', 'lh.max_created_at');
            })
            ->join('assets as a', 'a.uuid', '=', 'h.asset_uuid')
            ->select(
                'h.uuid',
                'h.acq_code',
                'h.asset_uuid',
                'h.before_payload',
                'h.after_payload',
                'h.note',
                'h.pic_request_uid',
                'h.created_at',
                'a.asset_code',
                'a.description',
            )
            ->whereNull('h.deleted_at')
            ->orderByDesc('h.created_at'); // latest snapshot first

        // 3) Apply filters on the *latest* row of each asset
        if ($pic) {
            $q->where('h.pic_request_uid', $pic);
        }

        if ($assetLike) {
            $q->where(function ($w) use ($assetLike) {
                $w->where('a.asset_code', 'ilike', "%{$assetLike}%")
                    ->orWhere('a.description', 'ilike', "%{$assetLike}%");
            });
        }

        if ($pajak !== null && $pajak !== '') {
            if ($pajak === '1') {
                $q->whereRaw("
                coalesce(
                    (h.after_payload::jsonb->>'is_pajak')::int,
                    (h.before_payload::jsonb->>'is_pajak')::int,
                    0
                ) = 1
            ");
            } elseif ($pajak === '0') {
                $q->whereRaw("
                coalesce(
                    (h.after_payload::jsonb->>'is_pajak')::int,
                    (h.before_payload::jsonb->>'is_pajak')::int,
                    0
                ) = 0
            ");
            }
        }

        if ($capFrom) {
            $q->whereRaw("
            to_date(
                coalesce(
                    h.after_payload::jsonb->>'capitalization_date',
                    h.before_payload::jsonb->>'capitalization_date'
                ),
                'YYYY-MM-DD'
            ) >= ?
        ", [$capFrom]);
        }

        if ($capTo) {
            $q->whereRaw("
            to_date(
                coalesce(
                    h.after_payload::jsonb->>'capitalization_date',
                    h.before_payload::jsonb->>'capitalization_date'
                ),
                'YYYY-MM-DD'
            ) <= ?
        ", [$capTo]);
        }
        $dt = DataTables::of($q)
            ->addColumn('asset_label', fn($r) => "{$r->asset_code} - {$r->description}")
            ->addColumn('quantity', function ($r) {
                $after  = json_decode($r->after_payload ?? '[]', true) ?: [];
                $before = json_decode($r->before_payload ?? '[]', true) ?: [];
                $val    = data_get($after, 'quantity', data_get($before, 'quantity', 0));
                return (float) $val;
            })
            ->addColumn('kode_uom', function ($r) {
                static $uomMap = null;
                if ($uomMap === null) {
                    $uomMap = DB::table('master_uom')->whereNull('deleted_at')->pluck('name', 'kode')->toArray();
                }

                $after  = json_decode($r->after_payload ?? '[]', true) ?: [];
                $before = json_decode($r->before_payload ?? '[]', true) ?: [];
                $kode   = data_get($after, 'kode_uom', data_get($before, 'kode_uom'));

                if (!$kode) return null;
                $name = $uomMap[$kode] ?? null;
                return $name ? "{$kode} - {$name}" : $kode;
            })
            ->addColumn('price', function ($r) {
                $after  = json_decode($r->after_payload ?? '[]', true) ?: [];
                $before = json_decode($r->before_payload ?? '[]', true) ?: [];
                $val    = data_get($after, 'price', data_get($before, 'price', 0));
                return (float) $val;
            })
            ->addColumn('vat_in', function ($r) {
                $after  = json_decode($r->after_payload ?? '[]', true) ?: [];
                $before = json_decode($r->before_payload ?? '[]', true) ?: [];
                $val    = data_get($after, 'vat_in', data_get($before, 'vat_in', 0));
                return (float) $val;
            })
            ->addColumn('total', function ($r) {
                $after  = json_decode($r->after_payload ?? '[]', true) ?: [];
                $before = json_decode($r->before_payload ?? '[]', true) ?: [];
                $val    = data_get($after, 'total', data_get($before, 'total', 0));
                return (float) $val;
            })
            ->addColumn('actual_date', function ($r) {
                $after  = json_decode($r->after_payload ?? '[]', true) ?: [];
                $before = json_decode($r->before_payload ?? '[]', true) ?: [];
                return data_get($after, 'actual_date', data_get($before, 'actual_date'));
            })
            ->addColumn('capitalization_date', function ($r) {
                $after  = json_decode($r->after_payload ?? '[]', true) ?: [];
                $before = json_decode($r->before_payload ?? '[]', true) ?: [];
                return data_get($after, 'capitalization_date', data_get($before, 'capitalization_date'));
            })
            ->addColumn('created_at_fmt', function ($r) {
                return Carbon::parse($r->created_at)->timezone('Asia/Jakarta')->format('d M Y H:i');
            })
            ->addColumn('actions', function ($r) use ($canDelete) {
                $id = e($r->uuid);
                $btns = '<div class="btn-group btn-group-sm">';
                // if ($canDelete) $btns .= '<button class="btn btn-light-danger btn-delete" data-id="' . $id . '">Delete</button>';
                $btns .= '</div>';
                return $btns;
            })
            ->rawColumns(['actions']);

        $dt->filterColumn('asset_code', function ($query, $keyword) {
            $k = trim((string)$keyword);
            if ($k === '') return;
            $query->where(function ($w) use ($k) {
                $w->where('a.asset_code', 'ilike', "%{$k}%")
                    ->orWhere('a.description', 'ilike', "%{$k}%");
            });
        });

        $dt->filterColumn('quantity', function ($query, $keyword) {
            $k = trim((string)$keyword);
            if ($k === '') return;
            $query->whereRaw(
                "coalesce(h.after_payload::jsonb->>'quantity', h.before_payload::jsonb->>'quantity', '') ILIKE ?",
                ["%{$k}%"]
            );
        });

        $dt->filterColumn('kode_uom', function ($query, $keyword) {
            $k = trim((string)$keyword);
            if ($k === '') return;
            $query->whereRaw(
                "coalesce(h.after_payload::jsonb->>'kode_uom', h.before_payload::jsonb->>'kode_uom', '') ILIKE ?",
                ["%{$k}%"]
            );
        });

        foreach (['price', 'vat_in', 'total'] as $col) {
            $dt->filterColumn($col, function ($query, $keyword) use ($col) {
                $k = trim((string)$keyword);
                if ($k === '') return;
                $query->whereRaw(
                    "coalesce(h.after_payload::jsonb->>'{$col}', h.before_payload::jsonb->>'{$col}', '') ILIKE ?",
                    ["%{$k}%"]
                );
            });
        }

        $dt->filterColumn('actual_date', function ($query, $keyword) {
            $k = trim((string)$keyword);
            if ($k === '') return;
            $query->whereRaw(
                "coalesce(h.after_payload::jsonb->>'actual_date', h.before_payload::jsonb->>'actual_date', '') ILIKE ?",
                ["%{$k}%"]
            );
        });

        $dt->filterColumn('capitalization_date', function ($query, $keyword) {
            $k = trim((string)$keyword);
            if ($k === '') return;
            $query->whereRaw(
                "coalesce(h.after_payload::jsonb->>'capitalization_date', h.before_payload::jsonb->>'capitalization_date', '') ILIKE ?",
                ["%{$k}%"]
            );
        });

        // optional safety (since your JS uses name:'note' right now)
        $dt->filterColumn('note', function ($query, $keyword) {
            $k = trim((string)$keyword);
            if ($k === '') return;
            $query->where('h.note', 'ilike', "%{$k}%");
        });

        return $dt->toJson();
    }

    private function parseDate(?string $v): ?string
    {
        if (!$v) return null;
        $v = trim($v);
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'];
        foreach ($formats as $f) {
            try {
                return Carbon::createFromFormat($f, $v)->format('Y-m-d');
            } catch (\Throwable $e) {
            }
        }
        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
    public function storeGlobal(Request $request)
    {
        abort_unless($request->user()?->hasAction('ACQUISITION', 'C'), 403);

        $data = $request->validate([
            'asset_uuid'          => ['required', 'uuid', 'exists:assets,uuid'],
            'quantity'            => ['required', 'numeric', 'min:0'],
            'kode_uom'            => ['required', 'string', 'max:50'],
            'price'               => ['required', 'numeric', 'min:0'],
            'is_pajak'            => ['nullable', 'boolean'],
            'useful_life_month'   => ['nullable', 'numeric', 'min:0'],
            'actual_date'         => ['nullable', 'string'],
            'capitalization_date' => ['nullable', 'string'],
            'note'                => ['nullable', 'string', 'max:1000'],
            'nilai_pajak'         => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->store($data['asset_uuid'], $request);
    }

    public function store(string $assetUuid, Request $request)
    {
        $data = $request->validate([
            'quantity'            => ['required', 'numeric', 'min:0'],
            'kode_uom'            => ['required', 'string', 'max:50'],
            'price'               => ['required', 'numeric', 'min:0'],
            'is_pajak'            => ['nullable', 'boolean'],
            'useful_life_month'   => ['nullable', 'numeric', 'min:0'],
            'actual_date'         => ['nullable', 'string'],
            'capitalization_date' => ['nullable', 'string'],
            'note'                => ['nullable', 'string', 'max:1000'],
            'nilai_pajak'         => ['nullable', 'numeric', 'min:0'],
        ]);

        $existsUom = DB::table('master_uom')
            ->whereNull('deleted_at')
            ->where('kode', $data['kode_uom'])
            ->exists();
        abort_unless($existsUom, 422, 'Invalid UOM.');

        $tableValues = DB::getSchemaBuilder()->hasTable('assets_values') ? 'assets_values' : 'assets_value';

        $existing = DB::table($tableValues)->where('asset_uuid', $assetUuid)->first();

        $nilaiPajak = isset($data['nilai_pajak']) ? (float) $data['nilai_pajak'] : 0.0;
        $rate       = $nilaiPajak / 100.0;

        $qty   = (float) $data['quantity'];
        $price = (float) $data['price'];

        $existingIsPajak = $existing ? (bool) $existing->is_pajak : false;
        $isPajak = array_key_exists('is_pajak', $data)
            ? (bool) $data['is_pajak']
            : $existingIsPajak;

        $subtotal = $qty * $price;
        $vat      = $isPajak ? round($subtotal * $rate, 2) : 0.0;
        $total    = round($subtotal + $vat, 2);

        $values = [
            'quantity'          => $qty,
            'kode_uom'          => $data['kode_uom'],
            'price'             => $price,
            'is_pajak'          => $isPajak ? 1 : 0,
            'vat_in'            => $vat,
            'total'             => $total,
            'useful_life_month' => $data['useful_life_month'] ?? null,
            'useful_life_year'  => isset($data['useful_life_month'])
                ? round(((float) $data['useful_life_month']) / 12, 2)
                : null,
            'actual_date'         => $this->parseDate($data['actual_date'] ?? null),
            'capitalization_date' => $this->parseDate($data['capitalization_date'] ?? null),
        ];

        $uid = auth()->user()?->name;
        abort_if(!$uid, 401, 'No session UID.');

        DB::beginTransaction();
        try {
            $action = $existing ? 'update' : 'create';

            if ($existing) {
                DB::table($tableValues)
                    ->where('asset_uuid', $assetUuid)
                    ->update(array_merge($values, [
                        'updated_at' => now(),
                    ]));
            } else {
                DB::table($tableValues)->insert(array_merge($values, [
                    'asset_uuid' => $assetUuid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $now    = Carbon::now();
            $prefix = 'ACQ' . $now->format('ym');
            $last   = AssetsValueHistory::where('acq_code', 'like', $prefix . '%')
                ->orderBy('acq_code', 'desc')
                ->first();

            $seq  = $last ? ((int) substr($last->acq_code, -4) + 1) : 1;
            $code = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

            AssetsValueHistory::create([
                'uuid'       => (string) Str::uuid(),
                'asset_uuid' => $assetUuid,
                'before_payload' => $existing ? [
                    'quantity'            => (float) $existing->quantity,
                    'kode_uom'            => $existing->kode_uom,
                    'price'               => (float) $existing->price,
                    'is_pajak'            => $existing->is_pajak ?? null,
                    'vat_in'              => (float) $existing->vat_in,
                    'total'               => (float) $existing->total,
                    'useful_life_month'   => $existing->useful_life_month,
                    'useful_life_year'    => $existing->useful_life_year,
                    'actual_date'         => $existing->actual_date,
                    'capitalization_date' => $existing->capitalization_date,
                ] : null,
                'after_payload'   => $values,
                'pic_request_uid' => $uid,
                'note'            => $data['note'] ?? null,
                'acq_code'        => $code,
            ]);

            $this->autoGenerateDeprAfterAcquisition($assetUuid);

            DB::commit();
            return response()->json(['ok' => true, 'action' => $action]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function autoGenerateDeprAfterAcquisition(string $assetUuid): void
    {
        // skip disposed assets
        $asset = DB::table('assets')->select('uuid', 'kode_status')->where('uuid', $assetUuid)->first();
        if (!$asset || $asset->kode_status === 'DIS') return;

        $av = DB::table('assets_value')
            ->select('capitalization_date', 'actual_date', 'created_at', 'total', 'useful_life_month', 'useful_life_year')
            ->where('asset_uuid', $assetUuid)
            ->first();

        if (!$av) return;

        $capDateRaw = $av->capitalization_date ?? $av->actual_date ?? $av->created_at;
        if (!$capDateRaw) return;

        $deprStartDate = Carbon::parse($capDateRaw)->startOfDay();

        $lifeMonths = (int) ($av->useful_life_month ?? 0);
        if ($lifeMonths <= 0 && isset($av->useful_life_year)) {
            $lifeMonths = (int) round(((float) $av->useful_life_year) * 12);
        }
        if ($lifeMonths <= 0) $lifeMonths = 60;

        $policy = AssetDeprPolicy::where('asset_uuid', $assetUuid)->first();
        if (!$policy) {
            AssetDeprPolicy::create([
                'asset_uuid'         => $assetUuid,
                'method'             => AssetDeprPolicy::METHOD_SL,
                'useful_life_months' => $lifeMonths,
                'salvage_value'      => 0,
                'depr_start_date'    => $deprStartDate->toDateString(),
                'convention'         => AssetDeprPolicy::CONVENTION_PRORATA_MONTH,
                'cutoff_day'         => 16,
                'start_rule'         => 'CUT_OFF_NEXT_OR_NEXT2',
                'is_active'          => true,
            ]);
        } else {
            $updates = [];
            if (!$policy->depr_start_date || Carbon::parse($deprStartDate)->lt(Carbon::parse($policy->depr_start_date))) {
                $updates['depr_start_date'] = $deprStartDate->toDateString();
            }
            if ($lifeMonths > 0 && (int)($policy->useful_life_months ?? 0) !== $lifeMonths) {
                $updates['useful_life_months'] = $lifeMonths;
            }
            if (!empty($updates)) {
                AssetDeprPolicy::where('asset_uuid', $assetUuid)->update($updates);
            }
        }

        $from = $deprStartDate->copy()->startOfMonth();
        $to   = now()->startOfMonth();

        AssetDeprMonthly::where('asset_uuid', $assetUuid)
            ->whereDate('period', '>=', $from->toDateString())
            ->delete();

        $p = AssetDeprPolicy::where('asset_uuid', $assetUuid)->where('is_active', true)->first();
        if (!$p) return;

        $this->generateMonthlyDeprRangeForAsset($assetUuid, $p, $from, $to);
    }

    private function generateMonthlyDeprRangeForAsset(string $assetUuid, AssetDeprPolicy $policy, Carbon $from, Carbon $to): void
    {
        $from = $from->copy()->startOfMonth();
        $to   = $to->copy()->startOfMonth();

        $av = DB::table('assets_value')
            ->select('capitalization_date', 'actual_date', 'created_at', 'total')
            ->where('asset_uuid', $assetUuid)
            ->first();

        $capDate  = $av?->capitalization_date ?? $av?->actual_date ?? $av?->created_at;
        $capTotal = (float) ($av?->total ?? 0);

        if ($capDate) {
            $assetStartMonth = Carbon::parse($capDate)->startOfMonth();
            if ($to->lt($assetStartMonth)) return;
            if ($from->lt($assetStartMonth)) $from = $assetStartMonth->copy();
        }

        $lifeMonths = (int) ($policy->useful_life_months ?: 60);
        if ($lifeMonths <= 0) $lifeMonths = 60;

        $startBase = $policy->depr_start_date
            ?: ($capDate ? Carbon::parse($capDate)->toDateString() : null);

        $cutoff = (int)($policy->cutoff_day ?? 16);

        $eligibleStart = $startBase
            ? Carbon::parse($startBase)->startOfMonth()->addMonths(
                (Carbon::parse($startBase)->day <= $cutoff) ? 1 : 2
            )
            : $from->copy();

        $cursor = $from->copy();
        while ($cursor->lte($to)) {

            $prev = AssetDeprMonthly::where('asset_uuid', $assetUuid)
                ->whereDate('period', '<', $cursor->toDateString())
                ->orderBy('period', 'desc')
                ->first();

            $opening = (float) ($prev->ending_balance ?? $capTotal);
            $accPrev = (float) ($prev->accumulated_depr_end ?? 0.0);

            $tin = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                ->whereDate('period', $cursor->toDateString())
                ->where('category', AssetDeprMovement::TRANSFER_IN)
                ->sum('amount');

            $tout = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                ->whereDate('period', $cursor->toDateString())
                ->where('category', AssetDeprMovement::TRANSFER_OUT)
                ->sum('amount');

            $disposals = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                ->whereDate('period', $cursor->toDateString())
                ->where('category', AssetDeprMovement::DISPOSAL)
                ->sum('amount');

            $adjValue = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                ->whereDate('period', $cursor->toDateString())
                ->where('category', AssetDeprMovement::ADJUSTMENT_VALUE)
                ->sum('amount');

            $adjDepr = (float) AssetDeprMovement::where('asset_uuid', $assetUuid)
                ->whereDate('period', $cursor->toDateString())
                ->where('category', AssetDeprMovement::ADJUSTMENT_DEPRECIATION)
                ->sum('amount');

            $eligible = $eligibleStart->lte($cursor);

            $elapsed   = $eligible ? $eligibleStart->diffInMonths($cursor) : 0;
            $remaining = $eligible ? max(0, $lifeMonths - $elapsed) : 0;

            $deprBase = $eligible
                ? max(
                    ($opening + $adjValue + $tin - $tout - $disposals) - (float) $policy->salvage_value,
                    0.0
                )
                : 0.0;

            $deprExpense = ($eligible && $remaining > 0)
                ? floor($deprBase / $remaining)
                : 0.0;

            $additions = 0.0;

            $ending = $opening
                + $additions
                + $tin
                - $tout
                - $disposals
                + $adjValue
                - $deprExpense
                + $adjDepr;

            $accEnd = $accPrev + $deprExpense + $adjDepr;

            $prefix = 'DEP' . $cursor->format('ym');
            $last = AssetDeprMonthly::whereNotNull('depr_code')
                ->where('depr_code', 'like', $prefix . '%')
                ->orderBy('depr_code', 'desc')
                ->first();

            $seq = $last ? ((int) substr($last->depr_code, -4) + 1) : 1;
            $txCode = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

            AssetDeprMonthly::updateOrCreate(
                [
                    'asset_uuid' => $assetUuid,
                    'period'     => $cursor->toDateString(),
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

            $cursor->addMonth();
        }
    }

    public function destroy(Request $request, string $uuid)
    {
        abort_unless($request->user()?->hasAction('ACQUISITION', 'D'), 403);

        $tf = AssetsValueHistory::where('uuid', $uuid)->firstOrFail();
        $tf->delete();

        return response()->json(['ok' => true]);
    }

    public function dataByAsset(string $assetUuid, Request $request)
    {
        if (!$this->canReadAcquisition()) {
            return DataTables::of(collect())->toJson();
        }

        $user      = $request->user();
        $canDelete = $user && $user->hasAction('ACQUISITION', 'D');

        $latestByAcq = DB::table('assets_value_history')
            ->select(
                'asset_uuid',
                'acq_code',
                DB::raw('MAX(created_at) AS max_created_at')
            )
            ->whereNull('deleted_at')
            ->where('asset_uuid', $assetUuid)
            ->groupBy('asset_uuid', 'acq_code');

        $q = DB::table('assets_value_history as h')
            ->joinSub($latestByAcq, 'lh', function ($join) {
                $join->on('h.asset_uuid', '=', 'lh.asset_uuid')
                    ->on('h.acq_code', '=', 'lh.acq_code')
                    ->on('h.created_at', '=', 'lh.max_created_at');
            })
            ->where('h.asset_uuid', $assetUuid)
            ->whereNull('h.deleted_at')
            ->orderByDesc('h.created_at')
            ->select(
                'h.before_payload',
                'h.after_payload',
                'h.note',
                'h.pic_request_uid',
                'h.created_at',
                'h.acq_code',
                'h.uuid'
            );

        $money = fn($v) => is_null($v) ? '—' : number_format((float) $v, 2);
        $num   = fn($v) => is_null($v) ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $dstr  = fn($v) => $v ? e(\Illuminate\Support\Str::of($v)->limit(32)) : '—';

        return datatables()->of($q)
            ->addColumn('detail', function ($r) use ($money, $num, $dstr) {
                $before = json_decode($r->before_payload ?? '[]', true) ?: [];
                $after  = json_decode($r->after_payload  ?? '[]', true) ?: [];

                $row = function ($label, $b, $a, $kind = 'text') use ($money, $num, $dstr) {
                    $fmt = fn($x) => match ($kind) {
                        'money' => $money($x),
                        'num'   => $num($x),
                        default => $dstr($x),
                    };
                    $fb = $fmt($b);
                    $fa = $fmt($a);
                    if ($fb === $fa) {
                        return "<div><span class='fw-semibold'>{$label}:</span> {$fa}</div>";
                    }
                    return "<div><span class='fw-semibold'>{$label}:</span> {$fb} <span class='mx-1'>→</span> <span class='fw-bold'>{$fa}</span></div>";
                };

                $html  = "<div class='small'>";
                $html .= $row('Quantity', data_get($before, 'quantity'), data_get($after, 'quantity'), 'num');
                $html .= $row('UOM',      data_get($before, 'kode_uom'), data_get($after, 'kode_uom'));
                $html .= $row('Price',    data_get($before, 'price'),    data_get($after, 'price'), 'money');
                $html .= $row('VAT In',   data_get($before, 'vat_in'),   data_get($after, 'vat_in'), 'money');
                $html .= $row('Total',    data_get($before, 'total'),    data_get($after, 'total'), 'money');

                $html .= $row('Useful Life (mo)', data_get($before, 'useful_life_month'), data_get($after, 'useful_life_month'), 'num');
                $html .= $row('Useful Life (yr)', data_get($before, 'useful_life_year'),  data_get($after, 'useful_life_year'),  'num');

                $html .= $row('Actual Date',    data_get($before, 'actual_date'),        data_get($after, 'actual_date'));
                $html .= $row('Capitalization', data_get($before, 'capitalization_date'), data_get($after, 'capitalization_date'));

                $html .= "</div>";
                return $html;
            })
            ->addColumn('note', fn($r) => $r->note)
            ->addColumn('pic_request_uid', fn($r) => $r->pic_request_uid)
            ->addColumn('created_at', fn($r) => $r->created_at)
            ->addColumn('acq_code', fn($r) => $r->acq_code)
            ->addColumn('actions', function ($r) use ($canDelete) {
                $id   = e($r->uuid);
                $btns = '<div class="btn-group btn-group-sm">';
                if ($canDelete) {
                    // $btns .= '<button class="btn btn-light-danger btn-delete" data-id="' . $id . '">Delete</button>';
                }
                $btns .= '</div>';
                return $btns;
            })
            ->rawColumns(['detail', 'actions'])
            ->toJson();
    }


    public function latest(string $assetUuid)
    {
        $tableValues = DB::getSchemaBuilder()->hasTable('assets_values') ? 'assets_values' : 'assets_value';
        $v = DB::table($tableValues)->where('asset_uuid', $assetUuid)->first();
        return response()->json([
            'exists' => (bool)$v,
            'value'  => $v,
        ]);
    }
    protected function canReadAcquisition(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }
        return $user->hasAction('ACQUISITION', 'R');
    }
}
