<script>
    (function() {
        const CFG = window.DEPRECIATION_PAGE || {};
        const ROUTES = CFG.routes || {};

        const PERIOD = CFG.PERIOD; // YYYY-MM-01
        const CURRENT_YEAR = Number(CFG.CURRENT_YEAR || new Date().getFullYear());
        const DEFAULT_PERIOD_MONTH = CFG.DEFAULT_PERIOD_MONTH; // YYYY-MM
        const NOW_DATE = CFG.NOW_DATE || '';
        const CSRF = CFG.CSRF || '';
        const DEFAULT_PERIOD_FROM = "{{ $defaultFrom }}";
        const DEFAULT_PERIOD_TO = "{{ $defaultTo }}";

        const CURR_START = CFG.CURRENT_MONTH_START; // YYYY-MM-01
        const CURR_END = CFG.CURRENT_MONTH_END; // YYYY-MM-DD
        const CURR_TEXT = CFG.CURRENT_MONTH_TEXT; // "January 2026"

        const ADJ_START = CFG.ADJ_DATE_START || CURR_START;
        const ADJ_END = CFG.ADJ_DATE_END || CURR_END;
        const ADJ_DEFAULT = CFG.ADJ_DATE_DEFAULT || NOW_DATE || ADJ_START;
        const ADJ_TEXT = CFG.ADJ_DATE_TEXT || CURR_TEXT;

        const $formProc = $('#form-process-month');

        const $btnProcOpen = $('#btn-open-process-month');
        const $btnAdjOpen = $('#btn-open-adj-depr');
        const $procMonthInp = $('#proc-period-depr');

        const $procMonthDisp = $('#proc-period-display'); // DISPLAY text (readonly)

        const monthModal = new bootstrap.Modal(document.getElementById('modal-process-month'));
        const $modalAdj = new bootstrap.Modal(document.getElementById('modal-adj-depr'));

        const money = v => Intl.NumberFormat().format(Number(v ?? 0));
        const money2 = v =>
            new Intl.NumberFormat(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(Number(v ?? 0));

        const SHOW_URL_TPL = ROUTES.assetDetailTpl || '';

        const setBusyProc = busy => {
            if (busy) $btnProcOpen.attr('data-kt-indicator', 'on').prop('disabled', true);
            else $btnProcOpen.removeAttr('data-kt-indicator').prop('disabled', false);
        };


        let syncingPeriod = false;

        function ymAddMonths(ym, delta) {
            if (!ym) return '';
            const [y, m] = ym.split('-').map(Number);
            const d = new Date(y, m - 1, 1);
            d.setMonth(d.getMonth() + delta);
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
        }

        function syncDeprFromPeriod() {
            const p = $('#f-period').val();
            if (!p) return;
            $('#f-period-depr').val(ymAddMonths(p, -1));
        }

        function syncPeriodFromDepr() {
            const pd = $('#f-period-depr').val();
            if (!pd) return;
            $('#f-period').val(ymAddMonths(pd, +1));
        }

        if (!$('#f-period-depr').val()) {
            syncDeprFromPeriod();
        }

        $('#f-period').on('change', function() {
            if (syncingPeriod) return;
            syncingPeriod = true;
            syncDeprFromPeriod();
            syncingPeriod = false;

            updateProcessMonthButtonLabel();
        });

        $('#f-period-depr').on('change', function() {
            if (syncingPeriod) return;
            syncingPeriod = true;
            syncPeriodFromDepr();
            syncingPeriod = false;
        });

        function buildFilters() {
            return {
                period_from: $('#f-period-from').val() || '',
                period_to: $('#f-period-to').val() || '',
                asset_status: $('#f-asset-status').val() || '',
                cap_from: $('#f-cap-from').val() || '',
                cap_to: $('#f-cap-to').val() || '',
                asset_q: $('#f-asset').val() || '',
            };
        }


        function formatPeriodToText(periodMonth) {
            if (!periodMonth) return '';
            const [year, month] = periodMonth.split('-');
            const date = new Date(Number(year), Number(month) - 1, 1);
            return date.toLocaleDateString('en-US', {
                month: 'long',
                year: 'numeric'
            });
        }

        function formatPeriodDeprCode(periodMonth) {
            if (!periodMonth) return '';
            const [year, month] = periodMonth.split('-');
            const yy = String(year).slice(-2);
            const mm = String(month).padStart(2, '0');
            return yy + mm;
        }

        function updatePeriodText() {
            const from = $('#f-period-from').val() || DEFAULT_PERIOD_FROM;
            const to = $('#f-period-to').val() || DEFAULT_PERIOD_TO;

            if (from === to) {
                $('#currentMonthText').text(formatPeriodToText(from));
            } else {
                $('#currentMonthText').text(`${formatPeriodToText(from)} - ${formatPeriodToText(to)}`);
            }
        }


        function updateProcessMonthButtonLabel() {
            const periodMonth = $('#f-period').val() || DEFAULT_PERIOD_MONTH;
            const shownMonth = ymAddMonths(periodMonth, -1);
            $btnProcOpen.find('.indicator-label')
                .text(`Process Depreciation`);
        }

        // ini yang bikin: pilih Jan 2026, display field jadi Dec 2025, value tetap Jan 2026
        function updateProcPeriodUI(periodMonth) {
            if (!periodMonth) return;

            const shown = ymAddMonths(periodMonth, -1);

            if ($procMonthDisp.length) {
                $procMonthDisp.val(formatPeriodToText(shown));
            }

            const d = new Date(periodMonth);
            d.setMonth(d.getMonth() - 1);
            const monthName = d.toLocaleString('default', {
                month: 'long'
            });
            const yearFull = d.getFullYear();
            $('#notes-dpr-month').html('Depresiasi yang diproses adalah <b>PERIODE</b> ' + monthName + ' ' +
                yearFull);
        }

        // ===== Adjustment Depreciation modal open =====
        // ===== Adjustment Depreciation modal open =====
        $btnAdjOpen.on('click', () => {
            $('#form-adj-depr')[0].reset();
            $('#adj-asset-uuid').val('');
            $('#adj-asset').val(null).trigger('change');

            // Allow previous month + current month
            const $date = $('#adj-date');
            $date.attr('min', ADJ_START);
            $date.attr('max', ADJ_END);

            const defaultDate = ADJ_DEFAULT || NOW_DATE || ADJ_START;

            if (defaultDate < ADJ_START || defaultDate > ADJ_END) {
                $date.val(ADJ_START);
            } else {
                $date.val(defaultDate);
            }

            toastr?.info(`Adjustment boleh untuk periode: ${ADJ_TEXT}`);
            $modalAdj.show();
        });

        function setBusyAdj(busy) {
            const $btn = $('#btn-submit-adj');
            if (busy) $btn.attr('data-kt-indicator', 'on').prop('disabled', true);
            else $btn.removeAttr('data-kt-indicator').prop('disabled', false);
        }

        function isDateInAdjustmentRange(dateStr) {
            if (!dateStr) return false;
            return dateStr >= ADJ_START && dateStr <= ADJ_END;
        }

        $('#form-adj-depr').on('submit', function(e) {
            e.preventDefault();

            const assetUuid = $('#adj-asset-uuid').val();
            const amount = $('#adj-amount').val();
            const actualDate = $('#adj-date').val();

            if (!assetUuid) return toastr?.error('Please select asset');
            if (!amount || Number(amount) === 0) return toastr?.error('Amount cannot be 0');
            if (!actualDate) return toastr?.error('Please choose actual date');

            if (!isDateInAdjustmentRange(actualDate)) {
                return Swal.fire({
                    icon: 'warning',
                    title: 'Not allowed',
                    text: `Adjustment hanya boleh untuk bulan sekarang atau bulan sebelumnya (${ADJ_TEXT}).`,
                    confirmButtonColor: '#EA242A'
                });
            }

            Swal.fire({
                title: 'Save adjustment depreciation?',
                text: 'This will recompute depreciation from selected period if needed.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#EA242A',
                cancelButtonColor: '#B5B5B6',
                confirmButtonText: 'Yes',
                cancelButtonText: 'No',
            }).then(async (res) => {
                if (!res.isConfirmed) return;

                setBusyAdj(true);

                try {
                    const resp = await $.ajax({
                        url: ROUTES.adjDepr,
                        type: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': CSRF
                        },
                        data: {
                            asset_uuid: assetUuid,
                            amount: amount,
                            actual_date: actualDate,
                        }
                    });

                    if (resp?.ok === false) {
                        Swal.fire('Failed', resp?.message || 'Failed', 'error');
                        return;
                    }

                    toastr?.success(resp?.message || 'Saved');
                    bootstrap.Modal.getInstance(document.getElementById('modal-adj-depr'))
                        ?.hide();

                    $('#tbl-monthly').DataTable().ajax.reload(null, false);

                } catch (xhr) {
                    const rj = xhr?.responseJSON || {};

                    if (
                        rj?.code === 'ADJ_ONLY_CURRENT_PERIOD' ||
                        rj?.code === 'ADJ_ONLY_LATEST_DEPRECIATION_PERIOD' ||
                        rj?.code === 'ADJ_DATE_OUT_OF_RANGE' ||
                        rj?.code === 'PERIOD_NOT_PROCESSED' ||
                        rj?.code === 'PERIOD_NOT_CLOSED'
                    ) {
                        return Swal.fire('Not allowed', rj?.message || 'Not allowed',
                        'warning');
                    }

                    if (rj?.code === 'PREV_MONTH_NOT_PROCESSED') {
                        let msg = rj?.message || 'Prev month not processed';
                        if (rj?.need_period_text) msg +=
                            `\nPlease process: ${rj.need_period_text} first.`;
                        return Swal.fire('Blocked', msg, 'warning');
                    }

                    if (rj?.code === 'PREV_YEAR_NOT_BUILT') {
                        return Swal.fire('Blocked', rj?.message || 'Prev year not built',
                            'warning');
                    }

                    Swal.fire('Failed', rj?.message || 'Failed to save adjustment', 'error');
                } finally {
                    setBusyAdj(false);
                }
            });
        });



        $('#f-asset-status').select2({
            placeholder: 'All status',
            allowClear: true,
            width: '100%',
            ajax: {
                url: ROUTES.statusOptions,
                dataType: 'json',
                delay: 150,
                data: params => ({
                    q: params.term || '',
                    type: 'Asset',
                    page: params.page || 1,
                }),
                processResults: d => d,
            },
        });

        $('#adj-asset')
            .select2({
                dropdownParent: $('#modal-adj-depr'),
                placeholder: 'Search asset code/name…',
                allowClear: true,
                ajax: {
                    url: ROUTES.deprAssetSearch,
                    dataType: 'json',
                    delay: 250,
                    data: params => ({
                        q: params.term
                    }),
                    processResults: data => ({
                        results: (data?.data || data || []).map(it => ({
                            id: it.uuid || it.asset_uuid,
                            text: (it.asset_code ? it.asset_code : '') + (it.description ?
                                ' - ' + it.description : ''),
                        })),
                    }),
                },
            })
            .on('select2:select select2:clear', function() {
                $('#adj-asset-uuid').val($(this).val() || '');
            });

        // ===== DataTable =====
        const tbl = $('#tbl-monthly').DataTable({
            processing: true,
            serverSide: true,
            searchDelay: 400,
            scrollX: true,
            dom: "<'row mb-2'<'col-sm-6 d-flex align-items-center justify-conten-start dt-toolbar'l>" +
                "<'col-sm-6 d-flex align-items-center justify-content-end dt-toolbar'f>>" +
                "<'table-responsive'tr>" +
                "<'row'<'col-sm-12 col-md-5 d-flex align-items-center justify-content-center justify-content-md-start'i>" +
                "<'col-sm-12 col-md-7 d-flex align-items-center justify-content-center justify-content-md-end'p>>",
            searching: true,
            order: [
                [4, 'asc']
            ],
            ajax: {
                url: ROUTES.dtMonthly,
                data: d => Object.assign(d, buildFilters()),
            },
            columns: [{
                    data: 'asset_code',
                    name: 'asset_code',
                    render: function(data, type, row) {
                        if (type !== 'display') return data;
                        const url = (SHOW_URL_TPL || '').replace('__UUID__', encodeURIComponent(row
                            .asset_uuid));
                        return `<a href="${url}" class="text-primary fw-semibold">${data ?? ''}</a>`;
                    },
                },
                {
                    data: 'asset_name',
                    name: 'asset_name'
                },
                {
                    data: 'asset_status_label',
                    name: 'asset_status_label'
                },
                {
                    data: 'cap_date',
                    name: 'av.capitalization_date',
                    defaultContent: '',
                    render: function(iso, type) {
                        if (!iso) return '';
                        if (type === 'sort' || type === 'type') return iso;
                        const d = new Date(iso);
                        return new Intl.DateTimeFormat('en-GB', {
                            timeZone: 'Asia/Jakarta',
                            day: '2-digit',
                            month: 'long',
                            year: 'numeric',
                        }).format(d);
                    },
                },
                // {
                //     data: 'period',
                //     name: 'period',
                //     render: function(iso, type) {
                //         if (!iso) return '';
                //         if (type === 'sort' || type === 'type') return iso;
                //         const d = new Date(iso);
                //         return new Intl.DateTimeFormat('en-GB', {
                //             timeZone: 'Asia/Jakarta',
                //             month: 'long',
                //             year: 'numeric',
                //         }).format(d);
                //     },
                // },
                {
                    data: 'period_depr',
                    name: 'period_depr',
                    searchable: false,
                    render: function(iso, type) {
                        if (!iso) return '';
                        if (type === 'sort' || type === 'type') return iso;
                        const d = new Date(iso);
                        return new Intl.DateTimeFormat('en-GB', {
                            timeZone: 'Asia/Jakarta',
                            month: 'long',
                            year: 'numeric',
                        }).format(d);
                    },
                },
                {
                    data: 'opening_balance',
                    render: money,
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: 'total_value',
                    render: money2,
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: 'useful_life_months',
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: 'ending_balance_prev_year',
                    render: money2,
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: 'remaining_useful_life_months',
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: 'transfers_in',
                    render: money,
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: 'transfers_out',
                    render: money,
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: 'adjustment_depreciation',
                    render: money,
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: 'depr_expense',
                    render: money,
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: null,
                    className: 'text-end',
                    searchable: false,
                    render: (_, __, row) => {
                        const a = Number(row.additions || 0);
                        const ti = Number(row.transfers_in || 0);
                        const to = Number(row.transfers_out || 0);
                        const av = Number(row.adjustment_value || 0);
                        const ds = Number(row.disposals || 0);
                        return money(a + ti - to + av - ds);
                    },
                },
                {
                    data: 'accumulated_depr_end',
                    render: money,
                    className: 'text-end',
                    searchable: false
                },
                {
                    data: 'last_net_book_value',
                    render: money,
                    className: 'text-end',
                    searchable: false
                },
            ],
        });
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('period_from') || urlParams.get('period_to')) {
            tbl.ajax.reload();
            updatePeriodText();
        }

        tbl.on('xhr', function() {
            const json = tbl.ajax.json() || {};
            $('#depr-sum').text(money2(json.total_depr ?? 0));

            const from = $('#f-period-from').val();
            const to = $('#f-period-to').val();

            // optional: cuma tampilkan info kalau user memang filter single-month dan hasil kosong
            if (from && to && from === to && Number(json.recordsTotal || 0) === 0) {
                toastr?.info(
                    'Belum ada data untuk periode ini. Kalau belum diproses, klik "Process Depreciation Month".'
                );
            }
        });



        // initial render text
        updatePeriodText();
        updateProcessMonthButtonLabel();

        // ===== Filter & Reset & Export =====
        $('#btnFilter').on('click', function() {
            tbl.ajax.reload();
            updatePeriodText();
            updateProcessMonthButtonLabel();
        });

        $('#btnReset').on('click', function() {
            $('#f-period-from').val(DEFAULT_PERIOD_MONTH);
            $('#f-period-to').val(DEFAULT_PERIOD_TO);

            $('#f-asset-status').val('').trigger('change');
            $('#f-cap-from').val('');
            $('#f-cap-to').val('');
            $('#f-asset').val('');

            tbl.ajax.reload();
            updatePeriodText();
        });


        $('#btnExport').on('click', function(e) {
            e.preventDefault();
            const params = $.param(buildFilters());

            Swal.fire({
                title: 'Export depreciation data?',
                text: 'Export current filtered depreciation data to Excel.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#EA242A',
                cancelButtonColor: '#B5B5B6',
                confirmButtonText: 'Yes',
                cancelButtonText: 'No',
            }).then(result => {
                if (!result.isConfirmed) return;
                window.location = ROUTES.exportMonthly + '?' + params;
            });
        });

        // ===== Open Process Month modal =====
        $btnProcOpen.on('click', function() {
            const periodMonth = $('#f-period-from').val() || DEFAULT_PERIOD_FROM; // YYYY-MM
            $procMonthInp.val(periodMonth);

            // catatan display (optional)
            $('#notes-dpr-month').html('Periode yang diproses adalah <b>' + formatPeriodToText(
                periodMonth) + '</b>');
            $('#notes-dpr-posting').text('Posting ke bulan: ' + formatPeriodToText(periodMonth));

            monthModal.show();
        });


        // setiap user ganti month (value tetap), display dan notes berubah ke -1
        $procMonthInp.on('change', function() {
            updateProcPeriodUI($(this).val());
        });

        // ===== Submit Process Month =====
        $('#form-process-month-modal').on('submit', function(e) {
            e.preventDefault();

            const periodMonth = $procMonthInp.val(); // YYYY-MM
            if (!periodMonth) {
                toastr?.error('Please pick a month');
                return;
            }

            const period = periodMonth + '-01';
            $('#period').val(period);

            Swal.fire({
                title: `Process depreciation for ${formatPeriodToText(periodMonth)}?`,
                text: 'This will (re)calculate depreciation for the selected month.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#EA242A',
                cancelButtonColor: '#B5B5B6',
                confirmButtonText: 'Yes',
                cancelButtonText: 'No',
            }).then(result => {
                if (!result.isConfirmed) return;

                setBusyProc(true);
                monthModal.hide();

                $.ajax({
                        url: $formProc.attr('action'),
                        type: 'POST',
                        data: $formProc.serialize(),
                        headers: {
                            'X-CSRF-TOKEN': $('input[name="_token"]', $formProc).val()
                        },
                    })
                    .done(function(resp) {
                        if (resp && resp.ok === false) {
                            return Swal.fire({
                                icon: 'error',
                                title: 'Process failed',
                                text: resp.message || 'Failed',
                                confirmButtonColor: '#EA242A'
                            });
                        }
                        toastr?.success(`Processed ${formatPeriodToText(periodMonth)}`);
                        tbl.ajax.reload(null, false);
                    })
                    .fail(function(xhr) {
                        const rj = xhr.responseJSON;
                        let msg = rj?.message || 'Failed to process depreciation';
                        if (rj?.need_period_text) msg +=
                            `\nPlease process: ${rj.need_period_text} first.`;
                        Swal.fire({
                            icon: 'error',
                            title: 'Process failed',
                            text: msg,
                            confirmButtonColor: '#EA242A'
                        });
                    })
                    .always(function() {
                        setBusyProc(false);
                    });
            });
        });


    })();
</script>
