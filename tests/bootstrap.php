<?php

/**
 * Bootstrap for the unit tests.
 *
 * These tests deliberately do not boot Craft. Everything they cover — parsing, diffing,
 * handle derivation, URI compilation, type shorthands — is logic Archie owns outright,
 * and keeping it testable without a database is what keeps it honest.
 */

require __DIR__ . '/../vendor/autoload.php';
