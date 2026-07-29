<?php

use App\Http\Controllers\AcquisitionController;
use App\Http\Controllers\AssetsController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthLdapController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepreciationController;
use App\Http\Controllers\DisposalController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\LabelPrintingController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\MasterRoleController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TrashController;

// LOGIN ROUTE
Route::get('/',  [AuthLdapController::class, 'showLogin'])->name('ldap.login');
Route::post('/ldap-login', [AuthLdapController::class, 'login'])->name('ldap.login.post')->middleware('throttle:ldap-login');;

Route::middleware('ldap.session')->group(function () {
    // DASHBOARD ROUTE
    Route::get('/dashboard/monthly', [DashboardController::class, 'dashboard_monthly'])->name('dashboard.monthly');
    Route::get('/dashboard/data/monthly', [DashboardController::class, 'data_monthly'])->name('dashboard.data.monthly');
    Route::get('/dashboard/acquisition-monthly', [DashboardController::class, 'acquisitionMonthly'])
        ->name('dashboard.acquisition.monthly');
    Route::get('/dashboard/depr-monthly', [DashboardController::class, 'deprMonthly'])
        ->name('dashboard.depr.monthly');

    Route::get('/dashboard/owner-status', [DashboardController::class, 'ownerStatus'])
        ->name('dashboard.owner.status');

    Route::get('/dashboard/yearly', [DashboardController::class, 'dashboard_yearly'])->name('dashboard.yearly');
    Route::get('/dashboard/data/yearly', [DashboardController::class, 'data_yearly'])->name('dashboard.data.yearly');
    Route::get('/dashboard/acquisition/yearly', [DashboardController::class, 'acquisitionYearly'])
        ->name('dashboard.acquisition.yearly');
    Route::get('/dashboard/depr/yearly', [DashboardController::class, 'deprYearly'])
        ->name('dashboard.depr.yearly');


    // MASTER DATA ROUTE
    Route::prefix('master-data')->name('master.')->group(function () {
        Route::middleware('role.action:MASTER_DATA,R')->group(function () {
            // FRONT END MASTER DATA
            Route::get('/master-sumber',        [MasterDataController::class, 'master_sumber'])->name('sumber');
            Route::get('/master-company',       [MasterDataController::class, 'master_transaction'])->name('company');
            Route::get('/master-division',    [MasterDataController::class, 'master_division'])->name('division');
            Route::get('/master-category',      [MasterDataController::class, 'master_category'])->name('category');
            Route::get('/master-category-2',    [MasterDataController::class, 'master_category_2'])->name('category_2');
            Route::get('/master-sub-category',  [MasterDataController::class, 'master_sub_category'])->name('sub_category');
            Route::get('/master-location',      [MasterDataController::class, 'master_location'])->name('location');
            Route::get('/master-uom',           [MasterDataController::class, 'master_uom'])->name('uom');
            Route::get('/master-status',        [MasterDataController::class, 'master_status'])->name('status');
            Route::get('/master-asset-class',   [MasterDataController::class, 'master_asset_class'])->name('asset_class');
            Route::get('/master-user-code',     [MasterDataController::class, 'master_user_code'])->name('user_code');

            // DATATABLE MASTER DATA
            Route::get('/master-sumber/datatable',        [MasterDataController::class, 'master_sumber_data'])->name('sumber.data');
            Route::get('/master-transaction/datatable',   [MasterDataController::class, 'master_transaction_data'])->name('transaction.data');
            Route::get('/master-division/datatable',    [MasterDataController::class, 'master_division_data'])->name('division.data');
            Route::get('/master-category/datatable',      [MasterDataController::class, 'master_category_data'])->name('category.data');
            Route::get('/master-category-2/datatable',    [MasterDataController::class, 'master_category_2_data'])->name('category_2.data');
            Route::get('/master-sub-category/datatable',  [MasterDataController::class, 'master_sub_category_data'])->name('sub_category.data');
            Route::get('/master-location/datatable',      [MasterDataController::class, 'master_location_data'])->name('location.data');
            Route::get('/master-uom/datatable',           [MasterDataController::class, 'master_uom_data'])->name('uom.data');
            Route::get('/master-status/datatable',        [MasterDataController::class, 'master_status_data'])->name('status.data');
            Route::get('/master-asset-class/datatable',   [MasterDataController::class, 'master_asset_class_data'])->name('asset_class.data');
            Route::get('/master-user-code/datatable',     [MasterDataController::class, 'master_user_code_data'])->name('user_code.data');

            // SELECT MASTER DATA (AJAX)
            Route::get('/select-master-sumber',        [MasterDataController::class, 'select_master_sumber'])->name('sumber.options');
            Route::get('/select-master-transaction',   [MasterDataController::class, 'select_master_transaction'])->name('transaction.options');
            Route::get('/select-master-division',    [MasterDataController::class, 'select_master_division'])->name('division.options');
            Route::get('/select-master-category',      [MasterDataController::class, 'select_master_category'])->name('category.options');
            Route::get('/select-master-category-2',    [MasterDataController::class, 'select_master_category_2'])->name('category_2.options');
            Route::get('/select-master-sub-category',  [MasterDataController::class, 'select_master_sub_category'])->name('sub_category.options');
            Route::get('/select-master-location',      [MasterDataController::class, 'select_master_location'])->name('location.options');
            Route::get('/select-master-uom',           [MasterDataController::class, 'select_master_uom'])->name('uom.options');
            Route::get('/select-master-status',        [MasterDataController::class, 'select_master_status'])->name('status.options');
            Route::get('/select-master-asset-class',   [MasterDataController::class, 'select_master_asset_class'])->name('asset_class.options');
            Route::get('/select-master-user-code',     [MasterDataController::class, 'select_master_user_code'])->name('user_code.options');

            // SHOW (detail)
            Route::get('/master-sumber/{uuid}',        [MasterDataController::class, 'master_sumber_show'])->name('sumber.show');
            Route::get('/master-transaction/{uuid}',   [MasterDataController::class, 'master_transaction_show'])->name('transaction.show');
            Route::get('/master-division/{uuid}',    [MasterDataController::class, 'master_division_show'])->name('division.show');
            Route::get('/master-category/{uuid}',      [MasterDataController::class, 'master_category_show'])->name('category.show');
            Route::get('/master-category-2/{uuid}',    [MasterDataController::class, 'master_category_2_show'])->name('category_2.show');
            Route::get('/master-sub-category/{uuid}',  [MasterDataController::class, 'master_sub_category_show'])->name('sub_category.show');
            Route::get('/master-location/{uuid}',      [MasterDataController::class, 'master_location_show'])->name('location.show');
            Route::get('/master-uom/{uuid}',           [MasterDataController::class, 'master_uom_show'])->name('uom.show');
            Route::get('/master-status/{uuid}',        [MasterDataController::class, 'master_status_show'])->name('status.show');
            Route::get('/master-asset-class/{uuid}',   [MasterDataController::class, 'master_asset_class_show'])->name('asset_class.show');
            Route::get('/master-user-code/{uuid}',     [MasterDataController::class, 'master_user_code_show'])->name('user_code.show');
        });
        Route::middleware('role.action:MASTER_DATA,C,U')->group(function () {
            Route::post('/master-sumber/save',         [MasterDataController::class, 'master_sumber_save'])->name('sumber.save');
            Route::post('/master-transaction/save',    [MasterDataController::class, 'master_transaction_save'])->name('transaction.save');
            Route::post('/master-division/save',     [MasterDataController::class, 'master_division_save'])->name('division.save');
            Route::post('/master-category/save',       [MasterDataController::class, 'master_category_save'])->name('category.save');
            Route::post('/master-category-2/save',     [MasterDataController::class, 'master_category_2_save'])->name('category_2.save');
            Route::post('/master-sub-category/save',   [MasterDataController::class, 'master_sub_category_save'])->name('sub_category.save');
            Route::post('/master-location/save',       [MasterDataController::class, 'master_location_save'])->name('location.save');
            Route::post('/master-uom/save',            [MasterDataController::class, 'master_uom_save'])->name('uom.save');
            Route::post('/master-status/save',         [MasterDataController::class, 'master_status_save'])->name('status.save');
            Route::post('/master-asset-class/save',    [MasterDataController::class, 'master_asset_class_save'])->name('asset_class.save');
            Route::post('/master-user-code/save',      [MasterDataController::class, 'master_user_code_save'])->name('user_code.save');
        });

        Route::middleware('role.action:MASTER_DATA,D')->group(function () {
            Route::delete('/master-sumber/{uuid}',         [MasterDataController::class, 'master_sumber_delete'])->name('sumber.delete');
            Route::delete('/master-transaction/{uuid}',    [MasterDataController::class, 'master_transaction_delete'])->name('transaction.delete');
            Route::delete('/master-division/{uuid}',     [MasterDataController::class, 'master_division_delete'])->name('division.delete');
            Route::delete('/master-category/{uuid}',       [MasterDataController::class, 'master_category_delete'])->name('category.delete');
            Route::delete('/master-category-2/{uuid}',     [MasterDataController::class, 'master_category_2_delete'])->name('category_2.delete');
            Route::delete('/master-sub-category/{uuid}',   [MasterDataController::class, 'master_sub_category_delete'])->name('sub_category.delete');
            Route::delete('/master-location/{uuid}',       [MasterDataController::class, 'master_location_delete'])->name('location.delete');
            Route::delete('/master-uom/{uuid}',            [MasterDataController::class, 'master_uom_delete'])->name('uom.delete');
            Route::delete('/master-status/{uuid}',         [MasterDataController::class, 'master_status_delete'])->name('status.delete');
            Route::delete('/master-asset-class/{uuid}',    [MasterDataController::class, 'master_asset_class_delete'])->name('asset_class.delete');
            Route::delete('/master-user-code/{uuid}',      [MasterDataController::class, 'master_user_code_delete'])->name('user_code.delete');
        });
    });


    // ASSETS ROUTE    
    Route::prefix('assets')->name('assets.')->group(function () {
        Route::middleware('role.action:ASSETS,R')->group(function () {
            // FRONTEND
            Route::get('/',                [AssetsController::class, 'index'])->name('index');
            Route::get('/detail/{uuid}',   [AssetsController::class, 'detail'])->name('detail');
            Route::get('/brief/{uuid}',    [AssetsController::class, 'brief'])->name('brief');

            // DATATABLE
            Route::match(['GET', 'POST'], '/datatable-all', [AssetsController::class, 'datatable'])->name('datatable');

            // AJAX SELECT OPTIONS
            Route::get('/select-asset-parent',        [AssetsController::class, 'select_asset_parent'])->name('parent.options');
            Route::get('/asset-parent-meta/{uuid}',   [AssetsController::class, 'asset_parent_meta'])->name('parent.meta');
            Route::get('/select-assets',              [AssetsController::class, 'select_assets'])->name('options');

            Route::get('/download-template', [AssetsController::class, 'download_template'])->name('download.template');
        });

        Route::middleware('role.action:ASSETS,C')->group(function () {
            Route::get('/create',          [AssetsController::class, 'create'])->name('create');
            Route::post('/save',           [AssetsController::class, 'store'])->name('store');

            Route::get('/bulk-upload',     [AssetsController::class, 'bulk_upload'])->name('upload.bulk');
            Route::post('/upload-excel',   [AssetsController::class, 'upload_excel'])->name('upload.excel');
        });

        Route::middleware('role.action:ASSETS,U')->group(function () {
            Route::get('/edit/{uuid}',     [AssetsController::class, 'edit'])->name('edit');
            Route::put('/update/{uuid}',   [AssetsController::class, 'update'])->name('update');
        });

        Route::middleware('role.action:ASSETS,D')->group(function () {
            Route::delete('/delete/{uuid}', [AssetsController::class, 'destroy'])->name('destroy');
        });
    });

    // MOVEMENT ROUTE
    Route::prefix('movement')->name('transfer.')->group(function () {
        Route::get('/datatable/{asset}', [TransferController::class, 'datatable'])->name('data');
        Route::get('/datatable',        [TransferController::class, 'datatable_all'])->name('data.all');

        Route::middleware('role.action:MOVEMENT,R')->group(function () {
            Route::get('/show/{uuid}', [TransferController::class, 'show'])->name('show');
            Route::get('/form/{transfer}', [TransferController::class, 'downloadForm'])->name('form');
        });

        Route::middleware('role.action:MOVEMENT,C')->group(function () {
            Route::post('/create', [TransferController::class, 'store'])->name('store');
        });

        Route::middleware('role.action:MOVEMENT,U')->group(function () {
            Route::put('/update/{uuid}', [TransferController::class, 'update'])->name('update');
        });

        Route::middleware('role.action:MOVEMENT,D')->group(function () {
            Route::delete('/delete/{uuid}', [TransferController::class, 'destroy'])->name('destroy');
        });

        Route::middleware('role.action:MOVEMENT,APR')->group(function () {
            Route::post('/approve/{uuid}', [TransferController::class, 'approve'])->name('approve');
            Route::post('/reject/{uuid}', [TransferController::class, 'reject'])->name('reject');
            Route::post('/{uuid}/approve-step', [TransferController::class, 'approveStep'])
                ->name('approve-step');
        });
    });

    // DISPOSAL ROUTE
    Route::prefix('disposal')->name('disposal.')->group(function () {
        Route::middleware('role.action:DISPOSAL,R')->group(function () {
            Route::get('/datatable/{asset}', [DisposalController::class, 'datatable'])->name('data');
            Route::get('/datatable',        [DisposalController::class, 'datatable_all'])->name('data.all');
            Route::get('/show/{uuid}',      [DisposalController::class, 'show'])->name('show');
        });

        Route::post('/create', [DisposalController::class, 'store'])
            ->name('store')
            ->middleware('role.action:DISPOSAL,C');

        Route::put('/update/{uuid}', [DisposalController::class, 'update'])
            ->name('update')
            ->middleware('role.action:DISPOSAL,U');

        Route::delete('/delete/{uuid}', [DisposalController::class, 'destroy'])
            ->name('destroy')
            ->middleware('role.action:DISPOSAL,D');

        Route::post('/approve/{uuid}', [DisposalController::class, 'approve'])
            ->name('approve')
            ->middleware('role.action:DISPOSAL,APR');

        Route::post('/{uuid}/approve-step', [DisposalController::class, 'approveStep'])
            ->name('approve-step')
            ->middleware('role.action:DISPOSAL,APR');

        Route::get('/{disposal}/form', [DisposalController::class, 'downloadForm'])
            ->name('form')
            ->middleware('role.action:DISPOSAL,C');
        Route::get('/{disposal}/ba', [DisposalController::class, 'downloadBa'])
            ->name('ba')
            ->middleware('role.action:DISPOSAL,C');


        Route::post('/reject/{uuid}', [DisposalController::class, 'reject'])
            ->name('reject')
            ->middleware('role.action:DISPOSAL,APR');
    });

    // RETURN ROUTE
    Route::prefix('return')->name('return.')->group(function () {
        Route::middleware('role.action:RETURN,R')->group(function () {
            Route::get('/{asset}/data', [ReturnController::class, 'datatable_by_asset'])->name('data.asset');
            Route::get('/data',        [ReturnController::class, 'datatable_all'])->name('data');
        });

        Route::get('/options', [ReturnController::class, 'options'])
            ->name('options')
            ->middleware('role.action:RETURN,C');

        Route::post('/', [ReturnController::class, 'store'])
            ->name('store')
            ->middleware('role.action:RETURN,C');

        Route::delete('/{uuid}', [ReturnController::class, 'destroy'])
            ->name('destroy')
            ->middleware('role.action:RETURN,D');
    });

    // ACQUISITION ROUTE
    Route::prefix('acquisition')->name('acquisition.')->group(function () {
        Route::get('{asset}/data',   [AcquisitionController::class, 'dataByAsset'])->name('data');

        Route::middleware('role.action:ACQUISITION,R')->group(function () {
            Route::get('{asset}/latest', [AcquisitionController::class, 'latest'])->name('latest');
            Route::get('/data',          [AcquisitionController::class, 'dtGlobal'])->name('dt');
        });

        Route::middleware('role.action:ACQUISITION,C,U')->group(function () {
            Route::post('{asset}',       [AcquisitionController::class, 'store'])->name('store');
            Route::post('/global/save',  [AcquisitionController::class, 'storeGlobal'])->name('global.save');
        });
        Route::middleware('role.action:ACQUISITION,D')->group(function () {
            Route::delete('/{uuid}', [AcquisitionController::class, 'destroy'])->name('destroy');
        });
    });

    // STOCK OPNAME ROUTE
    Route::prefix('stock-opname')->name('stockopname.')->group(function () {
        Route::get('/correction', [StockOpnameController::class, 'index'])->name('index');
        Route::get('{asset}/data',   [StockOpnameController::class, 'dataByAsset'])->name('data');
        Route::get('/data',   [StockOpnameController::class, 'datatable'])->name('data.all');
        Route::post('/create/transfer', [StockOpnameController::class, 'store_transfer'])->name('transfer.store');
        Route::post('/create/disposal', [StockOpnameController::class, 'store_disposal'])->name('disposal.store');
        // Preview downloads (generate template based on selected asset before creating record)
        Route::get('/preview/transfer/{asset}', [StockOpnameController::class, 'previewTransferForm'])
            ->name('transfer.preview.form');
        Route::get('/preview/disposal/{asset}/form', [StockOpnameController::class, 'previewDisposalForm'])
            ->name('disposal.preview.form');
        Route::get('/preview/disposal/{asset}/ba', [StockOpnameController::class, 'previewDisposalBa'])
            ->name('disposal.preview.ba');
        // Post-create downloads (generate documents from created records)
        Route::get('/transfer/{uuid}/form', [StockOpnameController::class, 'downloadTransferForm'])
            ->name('transfer.download.form');
        Route::get('/disposal/{uuid}/form', [StockOpnameController::class, 'downloadDisposalForm'])
            ->name('disposal.download.form');
        Route::get('/disposal/{uuid}/ba', [StockOpnameController::class, 'downloadDisposalBa'])
            ->name('disposal.download.ba');
        // create projects for selected assets in stock opname
        Route::get('/create-projects', [StockOpnameController::class, 'selectIndex'])
            ->name('assets.select.index');
        Route::get('/assets/select/datatable', [StockOpnameController::class, 'selectDatatable'])
            ->name('assets.select.datatable');
        Route::post('/assets/select/submit', [StockOpnameController::class, 'selectSubmit'])
            ->name('assets.select.submit');
        Route::post('/assets/select/assign-project', [StockOpnameController::class, 'assignProject'])
            ->name('assets.select.assign_project');
        Route::get('/assets/select/export', [StockOpnameController::class, 'selectExportExcel'])
            ->name('assets.select.export');

        // manage of projects in stock opname
        Route::get('/projects', [StockOpnameController::class, 'projectIndex'])
            ->name('asset_projects.index');
        Route::get('/projects/datatable', [StockOpnameController::class, 'projectDatatable'])
            ->name('asset_projects.datatable');
        Route::get('/projects/{project:uuid}', [StockOpnameController::class, 'projectShow'])
            ->name('asset_projects.show');
        Route::get('/asset-projects/{project:uuid}/assets/datatable', [StockOpnameController::class, 'assetsProjectDatatable'])
            ->name('asset_projects.assets.datatable');
        // remove asset from project (ajax)
        Route::delete('/asset-projects/{project:uuid}/assets/{assetUuid}', [StockOpnameController::class, 'removeAssetProject'])
            ->name('asset_projects.assets.remove');
        // select2 project options
        Route::get('/asset-projects/options', [StockOpnameController::class, 'projectOptions'])
            ->name('asset_projects.options');
        Route::patch('/asset-projects/{project:uuid}/close', [StockOpnameController::class, 'projectClose'])
            ->name('asset_projects.close');
        Route::patch('/asset-projects/{project}/reopen', [StockOpnameController::class, 'reopenProject'])
            ->name('asset_projects.reopen');

        Route::get('/asset-projects/assets/options', [StockOpnameController::class, 'projectAssetOptions'])
            ->name('asset_projects.assets.options');
        Route::get('/asset-projects/{project}/assets/export-excel', [StockOpnameController::class, 'projectAssetsExportExcel'])
            ->name('asset_projects.assets.export_excel');
        Route::patch('/asset-projects/{project}/assets/{assetUuid}/stock-opname', [StockOpnameController::class, 'setStockOpnameDone'])
            ->name('asset_projects.assets.stockopname_done');
        Route::patch('/asset-projects/{project}/assets/stock-opname/bulk', [StockOpnameController::class, 'bulkSetStockOpnameDone'])
            ->name('asset_projects.assets.stockopname_bulk');
    });

    // DEPRECIATION ROUTE
    Route::prefix('depreciation')->name('depreciation.')->group(function () {
        Route::middleware('role.action:DEPRECIATION,R')->group(function () {
            Route::get('/', [DepreciationController::class, 'index'])->name('index');
            // DATATABLE
            Route::get('/monthly',   [DepreciationController::class, 'dtMonthly'])->name('dt.monthly');
            Route::get('/yearly',    [DepreciationController::class, 'dtYearly'])->name('dt.yearly');
            Route::get('/policies',  [DepreciationController::class, 'dtPolicies'])->name('dt.policies');
            // AJAX SELECT 2
            Route::get('/assets/search', [DepreciationController::class, 'assetSearch'])->name('assets.search');
            Route::get('/period',    [DepreciationController::class, 'periodIndex'])->name('period.index');
            Route::get('/period/dt', [DepreciationController::class, 'dtPeriod'])->name('dt.period');
        });

        Route::middleware('role.action:DEPRECIATION,C,U')->group(function () {
            // PROCESSING
            Route::post('/run-month', [DepreciationController::class, 'runMonth'])->name('run.month');

            Route::post('/build-year', [DepreciationController::class, 'buildYear'])->name('build.year');
            Route::post('/init-first-run', [DepreciationController::class, 'initFirstRun'])->name('init.first-run');
            Route::post('/rollback-year', [DepreciationController::class, 'rollbackYear'])->name('rollback.year');
            Route::get('/year/status', [DepreciationController::class, 'yearStatus'])->name('year.status');

            Route::post('/transfer/adjustment-value',         [DepreciationController::class, 'recordAdjustmentValue'])->name('mv.adj.value');
            Route::post('/transfer/adjustment-depreciation',  [DepreciationController::class, 'recordAdjustmentDepreciation'])->name('mv.adj.depr');
        });

        // VALUE MOVEMENT
        Route::post('/transfer/addition',                 [DepreciationController::class, 'recordAddition'])->name('mv.addition');
        Route::post('/transfer/transfer',                 [DepreciationController::class, 'recordTransfer'])->name('mv.transfer');
        Route::get('/transfer/transfer-limit',            [DepreciationController::class, 'transferLimit'])->name('mv.transfer.limit');
        Route::post('/transfer/disposal',                 [DepreciationController::class, 'recordDisposal'])->name('mv.disposal');
        Route::get('/transfer/carryover-preview', [DepreciationController::class, 'carryOverPreview'])->name('mv.carryover.preview');

        // TRANSFER REQUESTS
        Route::prefix('transfer-requests')->name('transfer-requests.')->group(function () {
            Route::middleware('role.action:TRANSFER,R')->group(function () {
                Route::get('/',   [DepreciationController::class, 'transferRequestsIndex'])->name('index');
                Route::get('/dt', [DepreciationController::class, 'dtTransferRequests'])->name('dt');
                Route::get('/{uuid}/attachment', [DepreciationController::class, 'downloadTransferRequestAttachment'])
                    ->name('attachment');
            });
            Route::middleware('role.action:TRANSFER,C')->group(function () {
                Route::post('/', [DepreciationController::class, 'storeTransferRequest'])->name('store');
            });
            Route::middleware('role.action:TRANSFER,U')->group(function () {
                Route::put('/{uuid}', [DepreciationController::class, 'updateTransferRequest'])->name('update');
            });
            Route::middleware('role.action:TRANSFER,APR')->group(function () {
                Route::post('/{uuid}/approve', [DepreciationController::class, 'approveTransferRequest'])
                    ->name('approve');
                Route::post('/{uuid}/reject', [DepreciationController::class, 'rejectTransferRequest'])
                    ->name('reject');
            });
            Route::middleware('role.action:TRANSFER,D')->group(function () {
                Route::delete('/{uuid}', [DepreciationController::class, 'destroyTransferRequest'])->name('destroy');
            });
        });
    });

    // FRONTEND TRANSACTION TO TRIGGER MENU OPEN AND ACTIVE AT SIDEBAR
    Route::prefix('transaction')->name('transaction.')->group(function () {
        Route::middleware('role.action:MOVEMENT,R')->group(function () {
            Route::get('/movement', [TransferController::class, 'index'])->name('transfer.index');
        });
        Route::middleware('role.action:DISPOSAL,R')->group(function () {
            Route::get('/disposal', [DisposalController::class, 'index'])->name('disposal.index');
        });
        Route::middleware('role.action:RETURN,R')->group(function () {
            Route::get('/return', [ReturnController::class, 'index'])->name('return.index');
        });
        Route::middleware('role.action:ACQUISITION,R')->group(function () {
            Route::get('/acquisition',        [AcquisitionController::class, 'index'])->name('acquisition.index');
        });
        Route::middleware('role.action:TRANSFER,R')->group(function () {
            Route::get('/transfer-requests', [DepreciationController::class, 'transferRequestsIndex'])->name('transfer-requests.index');
        });
    });

    // EXPORT ROUTE
    Route::prefix('export')->name('export.')->group(function () {
        Route::get('/master-asset-class', [ExportController::class, 'master_asset_class_export'])->name('master_asset_class');
        Route::get('/master-transaction', [ExportController::class, 'master_transaction_export'])->name('master_transaction');
        Route::get('/master-location', [ExportController::class, 'master_location_export'])->name('master_location');
        Route::get('/master-uom', [ExportController::class, 'master_uom_export'])->name('master_uom');
        Route::get('/master-status', [ExportController::class, 'master_status_export'])->name('master_status');
        Route::get('/master-user-code', [ExportController::class, 'master_user_code_export'])->name('master_user_code');
        Route::get('/master-sumber', [ExportController::class, 'master_sumber_export'])->name('master_sumber');
        Route::get('/assets', [ExportController::class, 'assets_export'])->name('assets');
        Route::get('/stock-opname', [ExportController::class, 'stock_opname_export'])->name('stockopname');
        Route::get('/movement', [ExportController::class, 'movement_export'])->name('movement');
        Route::get('/disposal', [ExportController::class, 'disposal_export'])->name('disposal');
        Route::get('/return', [ExportController::class, 'return_export'])->name('return');
        Route::get('/acquisition', [ExportController::class, 'acquisition_export'])->name('acquisition');
        Route::get('/transfer-requests', [ExportController::class, 'transfer_requests_export'])->name('transfer-requests');
        Route::get('/depreciation-monthly', [ExportController::class, 'depreciation_monthly_export'])->name('depreciation.monthly');
    });

    // LABEL PRINTING ROUTE
    Route::prefix('label-printing')->name('label.')->group(function () {
        // Pakai permission ASSETS,R (boleh lihat asset berarti boleh print label)
        Route::middleware('role.action:ASSETS,R')->group(function () {
            Route::get('/', [LabelPrintingController::class, 'index'])->name('index');
            Route::post('/print', [LabelPrintingController::class, 'print'])->name('print');
            Route::post('/print-rfid-lan', [LabelPrintingController::class, 'printRfidLan'])
                ->name('print-rfid-lan');
        });
    });


    // TRASH ROUTE
    Route::prefix('trash')->name('trash.')->group(function () {
        Route::middleware('role.action:TRASH,R')->group(function () {
            Route::get('/',              [TrashController::class, 'index'])->name('index');
            Route::get('/datatable',     [TrashController::class, 'data'])->name('data');
        });
        Route::middleware('role.action:TRASH,U')->group(function () {
            Route::post('/{type}/{id}/restore', [TrashController::class, 'restore'])->name('restore');
        });
        Route::middleware('role.action:TRASH,D')->group(function () {
            Route::delete('/{type}/{id}/force', [TrashController::class, 'force'])->name('force');
        });
    });

    Route::prefix('user-management')->name('settings.')->group(function () {
        Route::get('/roles', [MasterRoleController::class, 'index'])
            ->name('roles.index')
            ->middleware('role.action:USER_MGMT,R');
        Route::post('/roles', [MasterRoleController::class, 'store'])
            ->name('roles.store')
            ->middleware('role.action:USER_MGMT,C');
        Route::put('/roles/{uuid}', [MasterRoleController::class, 'update'])
            ->name('roles.update')
            ->middleware('role.action:USER_MGMT,U');
        Route::delete('/roles/{uuid}', [MasterRoleController::class, 'destroy'])
            ->name('roles.destroy')
            ->middleware('role.action:USER_MGMT,D');
        Route::get('/roles/{uuid}/edit', [MasterRoleController::class, 'edit'])
            ->name('roles.edit')
            ->middleware('role.action:USER_MGMT,U');
        Route::put('/roles/{uuid}', [MasterRoleController::class, 'update'])
            ->name('roles.update')
            ->middleware('role.action:USER_MGMT,U');

        Route::get('/users', [UserManagementController::class, 'index'])
            ->name('users.index')
            ->middleware('role.action:USER_MGMT,R');
        Route::get('/users/datatable', [UserManagementController::class, 'datatable'])
            ->name('users.datatable')
            ->middleware('role.action:USER_MGMT,R');
        Route::put('/users/{id}', [UserManagementController::class, 'update'])
            ->name('users.update')
            ->middleware('role.action:USER_MGMT,U');
        // web.php
        Route::put('/profile', [UserManagementController::class, 'profile_update'])
            ->name('profile.update');
    });

    Route::get('/reporting', [ReportingController::class, 'index'])->name('reporting.index')->middleware('role.action:REPORTING,R');
    Route::match(['GET', 'POST'], '/reporting/asset-depr/datatable', [ReportingController::class, 'datatableAssetDepr'])->name('reporting.asset_depr.datatable');
    Route::get('/reporting/asset-depr/export', [ReportingController::class, 'exportAssetDepr'])->name('reporting.asset_depr.export');

    Route::get('users/select-user', [UserManagementController::class, 'select_users'])->name('users.options');

    // LOGOUT ROUTE
    Route::post('/ldap-logout', [AuthLdapController::class, 'logout'])->name('ldap.logout');
});
require __DIR__ . '/auth.php';
