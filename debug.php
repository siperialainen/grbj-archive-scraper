<?php
require 'vendor/autoload.php';

$params = getopt('', ['maxResultsPerAuthor::', 'startDate::', 'endDate::', 'concurrency::', 'wait::']);

$scraper = new \siperialainen\GrbjArchiveScraper();

$error_messages = [];

try {
    $scraper->setMaxResultsPerAuthor(isset($params['maxResultsPerAuthor']) ? $params['maxResultsPerAuthor'] : 5);
} catch (InvalidArgumentException $e) {
    $error_messages[] = $e->getMessage() . PHP_EOL;
}
try {
    $scraper->setStartDate(isset($params['startDate']) ? $params['startDate'] : '2001-01-01');
} catch (InvalidArgumentException $e) {
    $error_messages[] = $e->getMessage() . PHP_EOL;
}
try {
    $scraper->setEndDate(isset($params['endDate']) ? $params['endDate'] : '2018-01-01');
} catch (InvalidArgumentException $e) {
    $error_messages[] = $e->getMessage() . PHP_EOL;
}
try {
    $scraper->setConcurrency(isset($params['concurrency']) ? $params['concurrency'] : 5);
} catch (InvalidArgumentException $e) {
    $error_messages[] = $e->getMessage() . PHP_EOL;
}
try {
    $scraper->setWait(isset($params['wait']) ? $params['wait'] : 0);
} catch (InvalidArgumentException $e) {
    $error_messages[] = $e->getMessage() . PHP_EOL;
}

if (!empty($error_messages)) {
    foreach ($error_messages as $error_message) {
        echo $error_message;
    }
} else {
    print_r($scraper->scrape());
}
