<?php
require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 'on');
ini_set('date.timezone', 'Europe/Moscow');

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\DoctrineServiceProvider;


$app = new Application();
$app->register(new TwigServiceProvider(), ['twig.path' => __DIR__ . '/../views']);
$app->register(new DoctrineServiceProvider(), ["db.options" => [
    "driver"=> "pdo_mysql",
    "host"=> "localhost",
    "dbname"=> "perf",
    "user"=> "root",
    "password"=> "123",
    "charset"=> "utf8"
]]);
$app->get('/', function(Request $request, Application $app) {
    $period = floatval($request->get('period'));
    if ($period < 1) $period = 4;
    $period *= 60 * 60;
    $start_time = time() - $period;
    $end_time = time();
    $step = $period / 50;
    $cpu_values = [];
    $mem_values = [];
    $swap_values = [];
    for ($i = $start_time; $i < $end_time; $i += $step) {
        $from = $i;
        $to = $from + $step;
        $query = "SELECT avg(value) as avg_val FROM cpu WHERE `time` between (from_unixtime({$from})) and (from_unixtime({$to})) ORDER BY `time` ASC";
        $st = $app['db']->fetchAssoc($query);
        $cpu_values[] = [$to, $st["avg_val"]];
        $query = "SELECT avg(value) as avg_val FROM mem WHERE `time` between (from_unixtime({$from})) and (from_unixtime({$to})) ORDER BY `time` ASC";
        $st = $app['db']->fetchAssoc($query);
        $mem_values[] = [$to, $st["avg_val"]];
        $query = "SELECT avg(value) as avg_val FROM swap WHERE `time` between (from_unixtime({$from})) and (from_unixtime({$to})) ORDER BY `time` ASC";
        $st = $app['db']->fetchAssoc($query);
        $swap_values[] = [$to, $st["avg_val"]];
        $query = "SELECT avg(value) as avg_val FROM io WHERE `time` between (from_unixtime({$from})) and (from_unixtime({$to})) ORDER BY `time` ASC";
        $st = $app['db']->fetchAssoc($query);
        $io_values[] = [$to, $st["avg_val"]];
    }

    function prepare($items) {
        return [($items[0] + 60 * 60 * 4) * 1000, 100 * round((float)$items[1], 5)];
    }

    function prepare_io($items) {
        return [($items[0] + 60 * 60 * 4) * 1000, round($items[1], 2)];
    }

    return $app['twig']->render('Index.twig', [
        "json_cpu_array" => json_encode(array_map("prepare", $cpu_values)),
        "json_mem_array" => json_encode(array_map("prepare", $mem_values)),
        "json_swap_array" => json_encode(array_map("prepare", $swap_values)),
        "json_io_array" => json_encode(array_map("prepare_io", $io_values)),
        "period" => $period,
    ] );
});

$app->error(function (\Exception $e, $code) {
    switch ($code) {
        case 404:
            $message = 'The requested page could not be found.';
            break;
        default:
            $message = $e->getMessage();
    }

    return new Response($message);
});

$app->run();
