<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

/** Internal-only taxonomy. Existing HTTP codes, envelopes and messages remain authoritative. */
enum InternalErrorCategory: string
{
    case Validation = 'validation';
    case Authorization = 'authorization';
    case UpstreamFailure = 'upstream_failure';
    case RateLimiting = 'rate_limiting';
    case TemporaryUnavailable = 'temporary_unavailable';
    case PersistenceFailure = 'persistence_failure';
    case InternalInvariantFailure = 'internal_invariant_failure';
}
