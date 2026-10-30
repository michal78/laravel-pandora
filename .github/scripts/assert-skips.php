<?php

declare(strict_types=1);

/*
 * Fails a CI leg whose skipped-test count is not the one it declares.
 *
 * A skip is how a leg says "the thing I exist to test is not here": the S3
 * tests skip without an endpoint, the pgvector tests without the extension.
 * A green run with an unexpected skip is a leg that stopped testing and kept
 * reporting success, which is how MinIO's disappearance could have gone
 * unnoticed. So the count is pinned exactly, in both directions: more skips
 * means something stopped running, fewer means a leg gained coverage and its
 * expectation should be lowered on purpose.
 *
 * Usage: php assert-skips.php <junit.xml> <expected>
 */

[, $path, $expected] = $argv + [null, null, null];

if (! is_string($path) || ! is_file($path) || ! is_numeric($expected)) {
    fwrite(STDERR, "usage: php assert-skips.php <junit.xml> <expected>\n");
    exit(2);
}

$xml = simplexml_load_file($path);

if ($xml === false) {
    fwrite(STDERR, "Could not parse {$path}\n");
    exit(2);
}

$skipped = [];

foreach ($xml->xpath('//testcase[skipped]') ?: [] as $case) {
    // JUnit carries no skip reason, so the class is the useful grouping: it
    // names the suite that stopped running.
    $skipped[(string) $case['class']][] = (string) $case['name'];
}

$count = array_sum(array_map('count', $skipped));

ksort($skipped);

foreach ($skipped as $class => $tests) {
    printf("%4d  %s\n", count($tests), $class);
}

if ($count !== (int) $expected) {
    printf("\n%d tests skipped; this leg expects exactly %d.\n", $count, (int) $expected);
    echo "If a leg stopped testing what it exists to test, fix the leg. If coverage\n";
    echo "changed on purpose, update EXPECTED_SKIPS for this leg in tests.yml.\n";
    exit(1);
}

printf("\n%d tests skipped, as expected.\n", $count);
