<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');
session_start();

function getServerMemoryUsage($getPercentage = true) {
    $memoryTotal = null;
    $memoryFree = null;

    if (stristr(PHP_OS, "win")) {
        // Get total physical memory (this is in bytes)
        $cmd = "wmic ComputerSystem get TotalPhysicalMemory";
        @exec($cmd, $outputTotalPhysicalMemory);

        // Get free physical memory (this is in kibibytes!)
        $cmd = "wmic OS get FreePhysicalMemory";
        @exec($cmd, $outputFreePhysicalMemory);

        if ($outputTotalPhysicalMemory && $outputFreePhysicalMemory) {
            // Find total value
            foreach ($outputTotalPhysicalMemory as $line) {
                if ($line && preg_match("/^[0-9]+\$/", $line)) {
                    $memoryTotal = $line;
                    break;
                }
            }

            // Find free value
            foreach ($outputFreePhysicalMemory as $line) {
                if ($line && preg_match("/^[0-9]+\$/", $line)) {
                    $memoryFree = $line;
                    $memoryFree *= 1024;  // convert from kibibytes to bytes
                    break;
                }
            }
        }
    } else {
        if (is_readable("/proc/meminfo")) {
            $stats = @file_get_contents("/proc/meminfo");

            if ($stats !== false) {
                // Separate lines
                $stats = str_replace(array("\r\n", "\n\r", "\r"), "\n", $stats);
                $stats = explode("\n", $stats);

                // Separate values and find correct lines for total and free mem
                foreach ($stats as $statLine) {
                    $statLineData = explode(":", trim($statLine));

                    //
                    // Extract size (TODO: It seems that (at least) the two values for total and free memory have the unit "kB" always. Is this correct?
                    //

                        // Total memory
                    if (count($statLineData) == 2 && trim($statLineData[0]) == "MemTotal") {
                        $memoryTotal = trim($statLineData[1]);
                        $memoryTotal = explode(" ", $memoryTotal);
                        $memoryTotal = $memoryTotal[0];
                        $memoryTotal *= 1024;  // convert from kibibytes to bytes
                    }

                    // Free memory
                    if (count($statLineData) == 2 && trim($statLineData[0]) == "MemFree") {
                        $memoryFree = trim($statLineData[1]);
                        $memoryFree = explode(" ", $memoryFree);
                        $memoryFree = $memoryFree[0];
                        $memoryFree *= 1024;  // convert from kibibytes to bytes
                    }
                }
            }
        }
    }

    if (is_null($memoryTotal) || is_null($memoryFree)) {
        return null;
    } else {
        if ($getPercentage) {
            return (100 - ($memoryFree * 100 / $memoryTotal));
        } else {
            return array(
                "total" => $memoryTotal,
                "free" => $memoryFree,
            );
        }
    }
}

function getNiceFileSize($bytes, $binaryPrefix = true) {
    if ($binaryPrefix) {
        $unit = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
        if ($bytes == 0)
            return '0 ' . $unit[0];
        return @round($bytes / pow(1024, ($i = floor(log($bytes, 1024)))), 2) . ' ' . (isset($unit[$i]) ? $unit[$i] : 'B');
    } else {
        $unit = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
        if ($bytes == 0)
            return '0 ' . $unit[0];
        return @round($bytes / pow(1000, ($i = floor(log($bytes, 1000)))), 2) . ' ' . (isset($unit[$i]) ? $unit[$i] : 'B');
    }
}

function _getServerLoadLinuxData() {
    if (is_readable("/proc/stat")) {
        $stats = @file_get_contents("/proc/stat");

        if ($stats !== false) {
            // Remove double spaces to make it easier to extract values with explode()
            $stats = preg_replace("/[[:blank:]]+/", " ", $stats);

            // Separate lines
            $stats = str_replace(array("\r\n", "\n\r", "\r"), "\n", $stats);
            $stats = explode("\n", $stats);

            // Separate values and find line for main CPU load
            foreach ($stats as $statLine) {
                $statLineData = explode(" ", trim($statLine));

                // Found!
                if
                (
                        (count($statLineData) >= 5) &&
                        ($statLineData[0] == "cpu")
                ) {
                    return array(
                        $statLineData[1],
                        $statLineData[2],
                        $statLineData[3],
                        $statLineData[4],
                    );
                }
            }
        }
    }

    return null;
}

function getServerLoad() {
    $load = null;

    if (stristr(PHP_OS, "win")) {
        $cmd = "wmic cpu get loadpercentage /all";
        @exec($cmd, $output);

        if ($output) {
            foreach ($output as $line) {
                if ($line && preg_match("/^[0-9]+\$/", $line)) {
                    $load = $line;
                    break;
                }
            }
        }
    } else {
        if (is_readable("/proc/stat")) {
            // Collect 2 samples - each with 1 second period
            // See: https://de.wikipedia.org/wiki/Load#Der_Load_Average_auf_Unix-Systemen
            $statData1 = _getServerLoadLinuxData();
            sleep(1);
            $statData2 = _getServerLoadLinuxData();

            if
            (
                    (!is_null($statData1)) &&
                    (!is_null($statData2))
            ) {
                // Get difference
                $statData2[0] -= $statData1[0];
                $statData2[1] -= $statData1[1];
                $statData2[2] -= $statData1[2];
                $statData2[3] -= $statData1[3];

                // Sum up the 4 values for User, Nice, System and Idle and calculate
                // the percentage of idle time (which is part of the 4 values!)
                $cpuTime = $statData2[0] + $statData2[1] + $statData2[2] + $statData2[3];

                // Invert percentage to get CPU time, not idle time
                $load = 100 - ($statData2[3] * 100 / $cpuTime);
            }
        }
    }

    return $load;
}

function FileSizeConvert($bytes) {
    $bytes = floatval($bytes);
    $arBytes = array(
        0 => array(
            "UNIT" => "TB",
            "VALUE" => pow(1024, 4)
        ),
        1 => array(
            "UNIT" => "GB",
            "VALUE" => pow(1024, 3)
        ),
        2 => array(
            "UNIT" => "MB",
            "VALUE" => pow(1024, 2)
        ),
        3 => array(
            "UNIT" => "KB",
            "VALUE" => 1024
        ),
        4 => array(
            "UNIT" => "B",
            "VALUE" => 1
        ),
    );

    foreach ($arBytes as $arItem) {
        if ($bytes >= $arItem["VALUE"]) {
            $result = $bytes / $arItem["VALUE"];
            $result = str_replace(".", ",", strval(round($result, 2))) . " " . $arItem["UNIT"];
            break;
        }
    }
    return $result;
}

function adicionaArray(&$array, $valor) {
    if (count($array) >= 20) {
        array_shift($array);
    }
    $array[] = $valor;
}

if (isset($_GET['request'])) {
    if (floatval($_GET['request']) < 0)
        $_SESSION['MONITORACAO']['REQUEST_TIME'][] = 0;
    else
        adicionaArray($_SESSION['MONITORACAO']['REQUEST_TIME'], floatval($_GET['request']));
} else {
    unset($_SESSION['MONITORACAO']);
    echo "<script>window.location.href = window.location.origin + window.location.pathname + '?request=0&p=monitoracao&dir=frm';</script>";
}

//Memória
$memUsage = getServerMemoryUsage(false);
$_SESSION['MONITORACAO']['dia'] = date('d/m/Y');
adicionaArray($_SESSION['MONITORACAO']['MEMORIA']['total'], floatval(getNiceFileSize($memUsage["total"])));
adicionaArray($_SESSION['MONITORACAO']['MEMORIA']['uso'], floatval(getNiceFileSize($memUsage["total"] - $memUsage["free"])));
adicionaArray($_SESSION['MONITORACAO']['MEMORIA']['uso_perc'], floatval(getNiceFileSize(($memUsage["total"] - $memUsage["free"]) * 100 / $memUsage["total"])));
adicionaArray($_SESSION['MONITORACAO']['tempo'], date('H:i:s'));


//Hard Drive
$HDTotal = floatval(FileSizeConvert(disk_total_space('/')));
$HDUso = floatval(FileSizeConvert(disk_free_space('/')));
$HDLivre = floatval($HDTotal - $HDUso);



//processador
$cpuLoad = getServerLoad();
adicionaArray($_SESSION['MONITORACAO']['CPU'], floatval($cpuLoad));
?>

<!DOCTYPE html>
<html> 
    <head>
        <meta charset="UTF-8">
        <title>Monitora&ccedil;&atilde;o</title>
        <meta name="viewport" content="width=device-width">

        <script src="https://code.highcharts.com/highcharts.js"></script>
        <script src="https://code.highcharts.com/modules/series-label.js"></script>
        <script src="https://code.highcharts.com/modules/exporting.js"></script>

        <style>
            .grafico{
                height: 388px; 
                width: 100%; 
                float: left;
            }
        </style>

    </head>
    <body>



        <div id="monitor" class="grafico"></div>
        <div id="request" class="grafico" style="height: 400px"></div>

        <script>

            Highcharts.chart('monitor', {
                chart: {
                    zoomType: 'x'
                },
                title: {
                    text: 'Monitoracao'
                },
                subtitle: {
                    text: '<?= $_SESSION['MONITORACAO']['dia']; ?>'
                },
                yAxis: {
                    title: {
                        text: 'Uso em %'
                    },
                    max: 130
                },
                xAxis: {
                    type: 'datetime',
                    categories: <?= json_encode($_SESSION['MONITORACAO']['tempo']); ?>
                },
                labels: {
                    items: [{
                            style: {
                                left: '50px',
                                top: '05px'
                            }
                        }]
                },
                series: [{
                        type: 'spline',
                        name: 'Memoria',
                        data: <?= json_encode($_SESSION['MONITORACAO']['MEMORIA']['uso_perc']); ?>
                    }, {
                        type: 'spline',
                        name: 'Processador',
                        data: <?= json_encode($_SESSION['MONITORACAO']['CPU']); ?>
                    },
                    {
                        type: 'pie',
                        name: 'Uso do HD',
                        data: [{
                                name: 'Livre',
                                y: <?= $HDLivre ?>
                            }, {
                                name: 'Ocupado',
                                y: <?= $HDUso ?>
                            }],
                        center: [100, 10],
                        size: 80,
                        showInLegend: false,
                        dataLabels: {
                            enabled: true,
                            format: '{point.percentage:.1f} %',
                        }
                    }]
            });

            Highcharts.chart('request', {
                chart: {
                    zoomType: 'x'
                },
                title: {
                    text: 'Tempo de Load'
                },
                yAxis: {
                    title: {
                        text: 'Tempo de carregamento da pagina (s)'
                    },
                    max: 10
                },
                xAxis: {
                    type: 'datetime',
                    categories: <?= json_encode($_SESSION['MONITORACAO']['tempo']); ?>
                },
                plotOptions: {
                    series: {
                        label: {
                            connectorAllowed: false
                        }

                    }
                },

                series: [{
                        name: 'Request',
                        data: <?= json_encode($_SESSION['MONITORACAO']['REQUEST_TIME']); ?>
                    }],

                responsive: {
                    rules: [{
                            condition: {
                                maxWidth: 500
                            },
                            chartOptions: {
                                legend: {
                                    layout: 'horizontal',
                                    align: 'center',
                                    verticalAlign: 'bottom'
                                }
                            }
                        }]
                }

            });

            var request = (window.performance.timing.unloadEventStart - window.performance.timing.connectStart) / 1000;
            setTimeout(function () {
                window.location.href = window.location.origin + window.location.pathname + '?request=' + request;
            }, 5000);
        </script>
    </body>
</html>