<?php

/* Create table and fill in a few rows for examples; for comments see 3-generic-with-yield.php */
function createGenericTable(\Amp\Mysql\Link $db): Generator {
    yield $db->query("CREATE TABLE IF NOT EXISTS tmp SELECT 1 AS a, 2 AS b");

    $statement = yield $db->prepare("INSERT INTO tmp (a, b) VALUES (?, ? * 2)");

    $promises = [];
    foreach (range(1, 5) as $num) {
        $promises[] = $statement->execute($num, $num);
    }

    return yield $promises;
}
