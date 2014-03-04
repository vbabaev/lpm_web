<?php
require __DIR__ . '/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 'on');
ini_set('date.timezone', 'Europe/Moscow');

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\DoctrineServiceProvider;

const FOLD_HOURLY = 'hourly';
const FOLD_DAILY = 'daily';
const FOLD_WEEKLY = 'weekly';

const TABLE_CPU = 'cpu';
const TABLE_MEM = 'mem';
const TABLE_SWAP = 'swap';
const TABLE_IO = 'io';

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

$app->get("/fold/{table}/{type}", function (Request $req, Application $app, $table, $type) {
    $from = $to = $period = null;

    switch ($table) {
        case TABLE_CPU:
        case TABLE_IO:
        case TABLE_MEM:
        case TABLE_SWAP:
            break;
        default:
            throw new \Exception("Wrong table");
    }

    switch ($type) {

        case FOLD_DAILY:
            $from = date("Y-m-d H:i:s", mktime(0, 0, 0, date("m"), date("d") - 2, date("Y")));
            $to = date("Y-m-d H:i:s", mktime(0, 0, 0, date("m"), date("d") - 1, date("Y")));
            $period = "1H";
            break;

        case FOLD_WEEKLY:
            $from = date("Y-m-d H:i:s", mktime(0, 0, 0, date("m"), date("d") - 8, date("Y")));
            $to = date("Y-m-d H:i:s", mktime(0, 0, 0, date("m"), date("d") - 2, date("Y")));
            $period = "4H";
            break;

        case FOLD_HOURLY:
            $from = date("Y-m-d H:i:s", mktime(0, 0, 0, date("m"), date("d") - 2, date("Y")));
            $to = date("Y-m-d H:i:s", mktime(-12, 0, 0, date("m"), date("d"), date("Y")));
            $period = "30M";
            break;

        default:
            throw new \Exception("Wrong fold period type");
    }

    if (is_null($from)) throw new \Exception("from bound was not set");
    if (is_null($to)) throw new \Exception("to bound was not set");
    if (is_null($period)) throw new \Exception("period value was not set");

    $from = new DateTime($from);
    $to = new DateTime($to);

    $select_st = $app['db']->prepare("SELECT avg(`value`) as `avg` FROM $table WHERE `time` BETWEEN ? AND ?");
    $delete_st = $app['db']->prepare("DELETE FROM $table WHERE `time` BETWEEN ? AND ?");
    $insert_st = $app['db']->prepare("INSERT INTO $table (`time`, `value`) VALUE(?, ?)");

    $app['db']->beginTransaction();
    $period_interval = new \DateInterval("PT$period");
    $one_second_interval = new \DateInterval("PT1S");
    for (; $from->getTimestamp() < $to->getTimestamp(); $from->add($period_interval)) {
        $end = clone $from;
        $end->add($period_interval);
        $end->sub($one_second_interval);

        $result = $select_st->execute([$from->format("Y-m-d H:i:s"), $end->format("Y-m-d H:i:s")]);
        if (false === $result) throw new \Exception("db fail");
        $rs = $select_st->fetch();
        if (is_null($rs['avg'])) continue;


        $result = $delete_st->execute([$from->format("Y-m-d H:i:s"), $end->format("Y-m-d H:i:s")]);
        if (false === $result) throw new \Exception("db fail");

        $result = $insert_st->execute([$from->format("Y-m-d H:i:s"), $rs['avg']]);
        if (false === $result) throw new \Exception("db fail");
    }
    $app['db']->commit();
    return new Response(sprintf("folding table `%s` with period `%s` done\n", $table, $type));
});

$app->error(function (\Exception $e, $code) {
    fprintf(STDERR, $e->getMessage() . "\n");
    return new Response("Error: " . $e->getMessage()  . "\n");
});

if (!isset($argv[1])) $argv[1] = 'get';
if (!isset($argv[2])) $argv[2] = '/help';
list($_, $method, $path) = $argv;
$request = Request::create($path, $method);
$app->run($request);