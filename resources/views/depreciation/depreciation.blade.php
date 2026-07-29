@php
    use Carbon\Carbon;

    $currentMonth = Carbon::now()->startOfMonth()->toDateString();
    $currentMonthText = Carbon::now()->isoFormat('MMMM YYYY');

    $adjDateStart = Carbon::now()->subMonthNoOverflow()->startOfMonth()->toDateString();
    $adjDateEnd = Carbon::now()->endOfMonth()->toDateString();
    $adjDateToday = Carbon::now()->toDateString();
    $adjDateText =
        Carbon::now()->subMonthNoOverflow()->isoFormat('MMMM YYYY') . ' - ' . Carbon::now()->isoFormat('MMMM YYYY');

    $prevYearLabel = Carbon::now()->year - 1;
    $currentYear = Carbon::parse($currentMonth)->year;

    $defaultFrom = request('period_from') ?: Carbon::now()->format('Y-m');
    $defaultTo = request('period_to') ?: $defaultFrom;
@endphp

@extends('layouts.app')

@section('title', 'Depreciation')

@section('content')
    <div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6 mb-10">
        <div id="kt_app_toolbar_container" class="app-container container-fluid d-flex flex-stack">
            <div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
                <h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">
                    Depreciation
                </h1>
                <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
                    <li class="breadcrumb-item text-muted">Depreciation</li>
                </ul>
            </div>
        </div>
    </div>


    <div id="kt_app_content" class="app-content flex-column-fluid">
        <!--begin::Content container-->
        <div id="kt_app_content_container" class="app-container container-fluid">
            <div class="card mb-6">
                <div class="card-body">
                    <div class="row g-3 align-items-end">

                        <div class="col-md-4">
                            <label class="form-label">Asset Status</label>
                            <select id="f-asset-status" class="form-select"></select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Tanggal Masuk From</label>
                            <input type="date" id="f-cap-from" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Tanggal Masuk To</label>
                            <input type="date" id="f-cap-to" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Period From</label>
                            <input type="month" id="f-period-from" class="form-control" value="{{ $defaultFrom }}">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Period To</label>
                            <input type="month" id="f-period-to" class="form-control" value="{{ $defaultTo }}">
                        </div>


                        <div class="col-md-4">
                            <label class="form-label">Asset (code / description)</label>
                            <input type="text" id="f-asset" class="form-control"
                                placeholder="e.g. A1101000002-00 / Laptop Dell">
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button id="btnFilter" class="btn btn-danger btn-sm me-2">Apply Filter</button>
                    <button id="btnReset" class="btn btn-light-danger btn-sm me-2">Reset</button>
                    {{-- <button id="btnExport" class="btn btn-light-danger btn-sm d-none d-md-block">Export Excel</button> --}}
                </div>
            </div>
            <div class="card">
                <div class="card-header align-items-center justify-content-between">
                    <div class="d-flex flex-column">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label fw-bold fs-3 mb-1" id="currentMonthText">{{ $currentMonthText }}</span>
                        </h3>
                    </div>

                    @canAction('DEPRECIATION','C')
                    <div class="card-toolbar">
                        {{-- Process Depreciation Month --}}
                        <form id="form-process-month" method="POST" action="{{ route('depreciation.run.month') }}"
                            class="d-inline-flex align-items-center gap-2 me-2">
                            @csrf
                            <input type="hidden" name="period" id="period" value="{{ $currentMonth }}">
                            <button type="button" id="btn-open-process-month" class="btn btn-danger btn-sm">
                                <span class="indicator-label">Process Depreciation Month</span>
                                <span class="indicator-progress">
                                    Processing… <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                                </span>
                            </button>
                        </form>

                        {{-- Adjustment Depreciation --}}
                        <button type="button" id="btn-open-adj-depr" class="btn btn-danger btn-sm">
                            Adjustment Depreciation
                        </button>
                    </div>
                    @endcanAction

                </div>

                <div class="card-body">
                    <table id="tbl-monthly"
                        class="table table-striped table-row-bordered table-column-bordered gy-5 gs-7 border rounded ">
                        <thead class="table-light">
                            <tr>
                                <th class="min-w-200px">Asset Code</th>
                                <th class="min-w-200px">Asset Description</th>
                                {{-- <th class="min-w-150px">Transaction Number</th> --}}
                                <th class="min-w-150px">Asset Status</th>
                                <th class="min-w-200px">Tanggal Masuk</th>
                                {{-- <th class="min-w-200px">Depreciation Date</th> --}}
                                <th class="min-w-200px">Depreciation Period</th>
                                <th class="min-w-200px">Awal</th>
                                <th class="min-w-200px">Total (Assets Value)</th>
                                <th class="min-w-200px">Useful Life (Month)</th>
                                <th class="min-w-200px">Ending Balance {{ $prevYearLabel }}</th>
                                <th class="min-w-200px">Remaining Useful Life</th>
                                <th class="min-w-200px">Transfer In</th>
                                <th class="min-w-200px">Transfer Out</th>
                                <th class="min-w-200px">Adjusment Depreciation</th>
                                <th class="min-w-200px">Depreciation</th>
                                <th class="min-w-200px">Total Addition</th>
                                <th class="min-w-200px">Accumulated Depreciation</th>
                                <th class="min-w-200px">Net Book Value</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr>
                                <th colspan="2">Total Depreciation (This Month)</th>
                                <th id="depr-sum" class="text-danger"></th>
                                <th></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    {{-- Process Depreciation Month - Month Picker --}}
    <div class="modal fade" id="modal-process-month" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Process Depreciation Month</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form id="form-process-month-modal" autocomplete="off">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label required">Depreciation Period (Month)</label>

                            {{-- user pick PERIODE (bulan depresiasi) --}}
                            <input type="month" readonly id="proc-period-depr" class="form-control"
                                value="{{ now()->startOfMonth()->format('Y-m') }}">

                            <small class="text-muted d-none">
                                Cutoff day: 16. If capitalization day &gt; 16, posting
                                will be next month.
                            </small>

                            <div class="mt-3">
                                <p class="mb-1" id="notes-dpr-month">
                                    Periode yang diproses adalah <b>PERIODE</b> -
                                </p>
                                <p class="mb-0 text-muted d-none" id="notes-dpr-posting">
                                    Posting ke bulan: -
                                </p>
                            </div>

                            {{-- computed month run (Y-m) --}}
                            <input type="hidden" id="proc-period-run" value="">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="btn-run-process-month" class="btn btn-danger">
                            <span class="indicator-label">Process</span>
                            <span class="indicator-progress">
                                Processing… <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                            </span>
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>


    {{-- Adjustment Depreciation Modal --}}
    <div class="modal fade" id="modal-adj-depr" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Adjustment Depreciation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form id="form-adj-depr" autocomplete="off">
                    @csrf
                    <div class="modal-body py-4">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label required">Asset</label>
                                <select id="adj-asset" class="form-select" style="width:100%"></select>
                                <input type="hidden" id="adj-asset-uuid" name="asset_uuid">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label required">Amount</label>
                                <input type="number" step="0.01" class="form-control" id="adj-amount"
                                    name="amount" placeholder="e.g. -1500000 or 1500000">
                                <small class="text-muted d-block mt-1">
                                    Positive value = increase Accumulation Depreciation, negative value = decrease
                                    Accumulation Depreciation.
                                </small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label required">Actual Date</label>
                                <input type="date" class="form-control" id="adj-date" name="actual_date"
                                    value="{{ $adjDateToday }}" min="{{ $adjDateStart }}" max="{{ $adjDateEnd }}">

                                <small class="text-muted d-block mt-1">
                                    Actual Date allowed for previous/current month: <b>{{ $adjDateText }}</b>
                                </small>
                            </div>

                            {{-- <div class="col-md-6">
                                <label class="form-label">Note (Optional)</label>
                                <textarea class="form-control" id="adj-note" name="note" placeholder="Optional note"></textarea>
                            </div> --}}
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="btn-submit-adj" class="btn btn-primary">
                            <span class="indicator-label">Save Adjustment</span>
                            <span class="indicator-progress">
                                Saving… <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                            </span>
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
@endsection
@push('scripts')
    <script>
        window.DEPRECIATION_PAGE = {
            PERIOD: @json($currentMonth),
            CURRENT_YEAR: @json($currentYear),
            DEFAULT_PERIOD_MONTH: @json(\Carbon\Carbon::parse($currentMonth)->format('Y-m')),
            NOW_DATE: @json(\Carbon\Carbon::now()->toDateString()),
            CSRF: @json(csrf_token()),
            CURRENT_MONTH_START: @json(\Carbon\Carbon::now()->startOfMonth()->toDateString()), // YYYY-MM-01
            CURRENT_MONTH_END: @json(\Carbon\Carbon::now()->endOfMonth()->toDateString()), // YYYY-MM-DD
            CURRENT_MONTH_TEXT: @json(\Carbon\Carbon::now()->isoFormat('MMMM YYYY')),
            ADJ_DATE_START: @json($adjDateStart),
            ADJ_DATE_END: @json($adjDateEnd),
            ADJ_DATE_DEFAULT: @json($adjDateToday),
            ADJ_DATE_TEXT: @json($adjDateText),

            routes: {
                dtMonthly: @json(route('depreciation.dt.monthly')),
                runMonth: @json(route('depreciation.run.month')),
                exportMonthly: @json(route('export.depreciation.monthly')),
                statusOptions: @json(route('master.status.options')),
                deprAssetSearch: @json(route('depreciation.assets.search')),
                adjDepr: @json(route('depreciation.mv.adj.depr')),
                assetDetailTpl: @json(route('assets.detail', '__UUID__')),
            }
        };
    </script>

    @include('depreciation.depreciation-js')
@endpush
