<?php

namespace App\Ingest;

use RuntimeException;

/**
 * Today's file of a site is at its ceiling; the shipper waits for the next UTC day.
 */
final class IngestLogFull extends RuntimeException {}
