<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Location;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Helper\Sample;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// require_once base_path('vendor') . '/phpoffice\phpspreadsheet/samples/Bootstrap.php';
// __DIR__ . '/../Bootstrap.php';

class ReportController extends Controller
{
    public $asset_columns = [
        ['value' => 'code', 'name' => 'Code'],
        ['value' => 'name', 'name' => 'Name'],
        ['value' => 'group', 'name' => 'Group'],
        ['value' => 'serial_no', 'name' => 'Serial'],
        ['value' => 'model', 'name' => 'Model'],
        ['value' => 'quantity', 'name' => 'Quantity'],
        ['value' => 'asset_locations', 'name' => 'Locations'],
        ['value' => 'details', 'name' => 'Details'],
    ];
    public function datas(Request $request)
    {
        $request->validate([
            'datas' => 'required',
        ]);
        $scope = $request->has('scope') ? $request->get('scope') : 'all';
        $datas = explode(",", $request->datas);
        return response([
            'groups' => in_array('groups', $datas) ? Asset::select('group')->whereNotNull('group')->when($scope != 'all', function ($query, $check_result) use ($scope) {
                if ($scope == 'lvt') {
                    return $query->where('group', 'LVT');
                } else {
                    return $query->where('group', '<>', 'LVT');
                }
            })->orderBy('group')->distinct()->get() : null,
            'locations' => in_array('locations', $datas) ? Location::select('id', 'name')->orderBy('name')->get() : null,
            'asset_columns' => $this->asset_columns,
        ]);
    }

    public function assetDetails(Request $request)
    {
        $sortBy = ($request->has('sort_by') && !is_null($request->get('sort_by'))) ? $request->get('sort_by') : 'name';
        $sortDirection = ($request->has('sort_direction') && !is_null($request->get('sort_direction'))) ? $request->get('sort_direction') : 'asc';

        $groups = $request->has('groups') ? $request->get('groups') : '';
        $scope = $request->has('scope') ? $request->get('scope') : 'all';
        try {
            $summarized = DB::table('asset_locations')
                ->selectRaw("asset_id, location_id, locations.name AS location_name, quantity")
                ->join('locations', 'locations.id', '=', 'location_id');

            $assets = Asset::select('assets.*', env('DB_CONNECTION') == 'pgsql' ? DB::raw("STRING_AGG(CONCAT_WS( ' ' , location_name, ' => ', summarized.quantity),'<br> ') AS asset_locations") : DB::raw("REPLACE(TRIM(GROUP_CONCAT(' ', location_name, ' => ', summarized.quantity)), ',', '<br>') AS `asset_locations`"))
                ->joinSub($summarized, 'summarized', function ($join) {
                    $join->on('assets.id', '=', 'summarized.asset_id');
                })
                ->when($groups != '', function ($query, $check_result) use ($groups) {
                    return $query->whereIn('group', explode(',', $groups));
                })
                ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                    if ($scope == 'lvt') {
                        return $query->where('group', 'LVT');
                    } else {
                        return $query->where('group', '<>', 'LVT');
                    }
                })
                ->groupBy('assets.id', 'assets.name')
                ->orderBy($sortBy, $sortDirection)
                ->get();
            return response([
                'report_data' => $assets,
            ]);
        } catch (Exception $ex) {
            report($ex);
            return response([
                'message' => 'Fail to get result.',
                'report_data' => null,
            ], 500);
        }
    }

    public function assetDetailsExcelExport(Request $request)
    {
        $sortBy = ($request->has('sort_by') && !is_null($request->get('sort_by'))) ? $request->get('sort_by') : 'name';
        $sortDirection = ($request->has('sort_direction') && !is_null($request->get('sort_direction'))) ? $request->get('sort_direction') : 'asc';

        $groups = $request->has('groups') ? $request->get('groups') : '';
        $scope = $request->has('scope') ? $request->get('scope') : 'all';
        $columns = $request->has('columns') ? $request->get('columns') : implode(',', array_column($this->asset_columns, 'value'));
        $column_count = count(explode(',', $columns));

        $assets = null;
        try {
            $summarized = DB::table('asset_locations')
                ->selectRaw("asset_id, location_id, locations.name AS location_name, quantity")
                ->join('locations', 'locations.id', '=', 'location_id');

            $assets = Asset::select('assets.*', env('DB_CONNECTION') == 'pgsql' ? DB::raw("STRING_AGG(CONCAT_WS( ' ' , location_name, ' => ', summarized.quantity),', ') AS asset_locations") : DB::raw("TRIM(GROUP_CONCAT(' ', location_name, ' => ', summarized.quantity)) AS `asset_locations`"))
                ->joinSub($summarized, 'summarized', function ($join) {
                    $join->on('assets.id', '=', 'summarized.asset_id');
                })
                ->when($groups != '', function ($query, $check_result) use ($groups) {
                    return $query->whereIn('group', explode(',', $groups));
                })
                ->when($scope != 'all', function ($query, $check_result) use ($scope) {
                    if ($scope == 'lvt') {
                        return $query->where('group', 'LVT');
                    } else {
                        return $query->where('group', '<>', 'LVT');
                    }
                })
                ->groupBy('assets.id', 'assets.name')
                ->orderBy($sortBy, $sortDirection)
                ->get();
        } catch (Exception $ex) {
            report($ex);
            return response([
                'message' => 'Fail to get result.',
                'report_data' => null,
            ], 500);
        }

        $helper = new Sample();
        if ($helper->isCli()) {
            $helper->log('This example should only be run from a Web Browser' . PHP_EOL);

            return;
        }
// Create new Spreadsheet object
        $spreadsheet = new Spreadsheet();

// Set document properties
        $spreadsheet->getProperties()->setCreator('Maarten Balliauw')
            ->setLastModifiedBy('Maarten Balliauw')
            ->setTitle('Office 2007 XLSX Test Document')
            ->setSubject('Office 2007 XLSX Test Document')
            ->setDescription('Test document for Office 2007 XLSX, generated using PHP classes.')
            ->setKeywords('office 2007 openxml php')
            ->setCategory('Test result file');

// Header
        $headerStyle = [
            'font' => [
                'bold' => true,
                'size' => 16,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
                'bottom' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
        ];
        $spreadsheet->setActiveSheetIndex(0)->mergeCells('A1:' . $this->number_to_alphabet($column_count + 1) . '1');
        $spreadsheet->getActiveSheet(0)->getStyle('A1:' . $this->number_to_alphabet($column_count + 1) . '1')->applyFromArray($headerStyle);
        $spreadsheet->setActiveSheetIndex(0)->setCellValue('A1', 'Asset Details Report');

// Columns
        $columnStyle = [
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
                'bottom' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
        ];
        $spreadsheet->getActiveSheet(0)->getStyle('A2')->applyFromArray($columnStyle);
        $spreadsheet->setActiveSheetIndex(0)->setCellValue('A2', 'No');
        $letterIndex = 2;
        foreach (explode(',', $columns) as $col) {
            $arr = array_filter($this->asset_columns, function ($ar) use ($col) {
                return ($ar['value'] == $col);
            });
            $spreadsheet->getActiveSheet(0)->getStyle($this->number_to_alphabet($letterIndex) . '2')->applyFromArray($columnStyle);
            $spreadsheet->setActiveSheetIndex(0)->setCellValue($this->number_to_alphabet($letterIndex) . '2', (array_values($arr)[0])['name']);
            $letterIndex++;
        }

// Rows
        $rowIndex = 3;
        foreach ($assets as $asset) {
            $spreadsheet->setActiveSheetIndex(0)->setCellValue('A' . $rowIndex, $rowIndex - 2); //Roll number
            $letterIndex = 2;
            foreach (explode(',', $columns) as $col) {
                $spreadsheet->setActiveSheetIndex(0)->setCellValue($this->number_to_alphabet($letterIndex) . $rowIndex, $asset[$col]);
                $letterIndex++;
            }
            $rowIndex++;
        }

// Auto Size
        // $spreadsheet->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
        // $letterIndex = 2;
        // foreach (explode(',', $columns) as $col) {
        //     $spreadsheet->getActiveSheet()->getColumnDimension($this->number_to_alphabet($letterIndex))->setAutoSize(true);
        //     $letterIndex++;
        // }

// Rename worksheet
        $spreadsheet->getActiveSheet()->setTitle('Asset Details');

// Set active sheet index to the first sheet, so Excel opens this as the first sheet
        $spreadsheet->setActiveSheetIndex(0);

// Redirect output to a client’s web browser (Xlsx)
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Asset Details Report.xlsx"');
        header('Cache-Control: max-age=0');
// If you're serving to IE 9, then the following may be needed
        header('Cache-Control: max-age=1');

// If you're serving to IE over SSL, then the following may be needed
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
        header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
        header('Pragma: public'); // HTTP/1.0

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }

    public function assetSummary(Request $request)
    {
        return response([
            'message' => 'Test2 success!',
        ]);
    }

    public function locations(Request $request)
    {
        /* return response(['report_data' => [[
        'mode'=>'span',
        'label'=>'Mammal',
        'children'=> [
        ['name'=>'Elephant','count'=>5],
        ['name'=>'Cat','count'=>15]
        ]
        ]]]); */
        $sortBy = ($request->has('sort_by') && !is_null($request->get('sort_by'))) ? $request->get('sort_by') : 'name';
        $sortDirection = ($request->has('sort_direction') && !is_null($request->get('sort_direction'))) ? $request->get('sort_direction') : 'asc';
        $scope = $request->has('scope') ? $request->get('scope') : 'all';
        $groups = $request->has('groups') ? $request->get('groups') : '';

        $flocations = $request->has('locations') ? $request->get('locations') : '';
        $columns = $request->has('columns') ? $request->get('columns') : implode(',', array_column($this->asset_columns, 'value'));

        $locations = null;
        try {
            $locations = Location::whereHas('branch', function (Builder $query) {
                $query->where('organization_id', request()->user()->organization_id);
            })
                ->when($flocations != '', function ($query, $check_result) use ($flocations) {
                    return $query->whereIn('id', explode(',', $flocations));
                })
                ->with(['children' => function ($query) use ($scope) {
                    if ($scope == 'lvt') {
                        return $query->whereHas('asset', function (Builder $q) {
                            $q->where('group', 'LVT');
                        });
                    } else if ($scope == 'all') {
                        return $query->whereHas('asset', function (Builder $q) {
                            $q->where('group', '<>', 'LVT');
                        });
                    }
                }])
                ->orderBy($sortBy, $sortDirection)
                ->get();

            foreach ($locations as $loc) {
                $loc->mode = 'span';
                $loc->label = $loc->name;
                $count = 1;
                foreach ($loc->children as $asset) {
                    $asset->no = $count++;
                    $asset->code = $asset->asset->code;
                    $asset->name = $asset->asset->name;
                    $asset->group = $asset->asset->group;
                }
            }
            return response([
                'message' => 'Success.',
                'report_data' => $locations,
            ]);
        } catch (Exception $ex) {
            report($ex);
            return response([
                'message' => 'Fail to get result.',
                'report_data' => null,
            ], 500);
        }
    }

    public function locationsExcelExport(Request $request)
    {
        $sortBy = ($request->has('sort_by') && !is_null($request->get('sort_by'))) ? $request->get('sort_by') : 'name';
        $sortDirection = ($request->has('sort_direction') && !is_null($request->get('sort_direction'))) ? $request->get('sort_direction') : 'asc';

        $scope = $request->has('scope') ? $request->get('scope') : 'all';
        $groups = $request->has('groups') ? $request->get('groups') : '';

        $flocations = $request->has('locations') ? $request->get('locations') : '';
        $columns = $request->has('columns') ? $request->get('columns') : implode(',', array_column($this->asset_columns, 'value'));
        $column_count = 3; // count(explode(',', $columns));

        $locations = null;
        try {
            $locations = Location::whereHas('branch', function (Builder $query) {
                $query->where('organization_id', request()->user()->organization_id);
            })
                ->when($flocations != '', function ($query, $check_result) use ($flocations) {
                    return $query->whereIn('id', explode(',', $flocations));
                })
                ->with(['children' => function ($query) use ($scope) {
                    if ($scope == 'lvt') {
                        return $query->whereHas('asset', function (Builder $q) {
                            $q->where('group', 'LVT');
                        });
                    } else if ($scope == 'all') {
                        return $query->whereHas('asset', function (Builder $q) {
                            $q->where('group', '<>', 'LVT');
                        });
                    }
                }])
                ->orderBy($sortBy, $sortDirection)
                ->get();
        } catch (Exception $ex) {
            report($ex);
            return response([
                'message' => 'Fail to get result.',
                'report_data' => null,
            ], 500);
        }

        $helper = new Sample();
        if ($helper->isCli()) {
            $helper->log('This example should only be run from a Web Browser' . PHP_EOL);

            return;
        }
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator('Maarten Balliauw')
            ->setLastModifiedBy('Maarten Balliauw')
            ->setTitle('Office 2007 XLSX Test Document')
            ->setSubject('Office 2007 XLSX Test Document')
            ->setDescription('Test document for Office 2007 XLSX, generated using PHP classes.')
            ->setKeywords('office 2007 openxml php')
            ->setCategory('Test result file');

// Header
        $headerStyle = [
            'font' => [
                'bold' => true,
                'size' => 16,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
                'bottom' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
        ];
        $spreadsheet->setActiveSheetIndex(0)->mergeCells('A1:' . $this->number_to_alphabet($column_count + 1) . '1');
        $spreadsheet->getActiveSheet(0)->getStyle('A1:' . $this->number_to_alphabet($column_count + 1) . '1')->applyFromArray($headerStyle);
        $spreadsheet->setActiveSheetIndex(0)->setCellValue('A1', 'Locations Report');

// Columns
        $columnStyle = [
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
                'bottom' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
        ];
        $spreadsheet->getActiveSheet(0)->getStyle('A2:D2')->applyFromArray($columnStyle);
        // $spreadsheet->setActiveSheetIndex(0)->setCellValue('A2', 'No');
        $spreadsheet->setActiveSheetIndex(0)->setCellValue('B2', 'Name');
        $spreadsheet->setActiveSheetIndex(0)->setCellValue('C2', 'Group');
        $spreadsheet->setActiveSheetIndex(0)->setCellValue('D2', 'Quantity');
        // $letterIndex = 2;
        // foreach (explode(',', $columns) as $col) {
        //     $arr = array_filter($this->asset_columns, function ($ar) use ($col) {
        //         return ($ar['value'] == $col);
        //     });
        //     $spreadsheet->getActiveSheet(0)->getStyle($this->number_to_alphabet($letterIndex) . '2')->applyFromArray($columnStyle);
        //     $spreadsheet->setActiveSheetIndex(0)->setCellValue($this->number_to_alphabet($letterIndex) . '2', (array_values($arr)[0])['name']);
        //     $letterIndex++;
        // }

// Rows
        $groupStyle = [
            'font' => [
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
                'bottom' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
        ];
        $rowIndex = 3;
        foreach ($locations as $loc) {
            $spreadsheet->setActiveSheetIndex(0)->setCellValue('A' . $rowIndex, $rowIndex - 2);
            $spreadsheet->setActiveSheetIndex(0)->mergeCells('A' . $rowIndex . ':' . $this->number_to_alphabet($column_count + 1) . $rowIndex);
            $spreadsheet->getActiveSheet(0)->getStyle('A' . $rowIndex . ':' . $this->number_to_alphabet($column_count + 1) . $rowIndex)->applyFromArray($columnStyle);
            $spreadsheet->setActiveSheetIndex(0)->setCellValue('A' . $rowIndex, $loc->name);
            $rowIndex++;
            $count = 1;
            foreach ($loc->children as $asset) {
                $spreadsheet->setActiveSheetIndex(0)->setCellValue('A' . $rowIndex, $count); //Roll number
                $spreadsheet->setActiveSheetIndex(0)->setCellValue('B' . $rowIndex, $asset->asset->name); //Name
                $spreadsheet->setActiveSheetIndex(0)->setCellValue('C' . $rowIndex, $asset->asset->group); //Name
                $spreadsheet->setActiveSheetIndex(0)->setCellValue('D' . $rowIndex, $asset->quantity); //Name
                $rowIndex++;
                $count++;
            }
            // $rowIndex++;
        }

// Auto Size
        $spreadsheet->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
        $letterIndex = 2;
        foreach (explode(',', $columns) as $col) {
            $spreadsheet->getActiveSheet()->getColumnDimension($this->number_to_alphabet($letterIndex))->setAutoSize(true);
            $letterIndex++;
        }

        $spreadsheet->getActiveSheet()->setTitle('Locations');
        $spreadsheet->setActiveSheetIndex(0);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Location Report.xlsx"');
        header('Cache-Control: max-age=0');
        header('Cache-Control: max-age=1');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
        header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
        header('Pragma: public'); // HTTP/1.0

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }

    public function number_to_alphabet($number)
    {
        $number = intval($number);
        if ($number <= 0) {
            return '';
        }
        $alphabet = '';
        while ($number != 0) {
            $p = ($number - 1) % 26;
            $number = intval(($number - $p) / 26);
            $alphabet = chr(65 + $p) . $alphabet;
        }
        return $alphabet;
    }
}
