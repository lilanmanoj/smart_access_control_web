<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a template sent with an enrolment was discarded. Returned to the panel
 * as `template_error`; the cases are the contract (device API 1.1.0).
 */
enum FingerprintTemplateError: string
{
    case SlotMissing = 'slot_missing';
    case UnsupportedFormat = 'unsupported_format';
    case InvalidEncoding = 'invalid_encoding';
    case SizeMismatch = 'size_mismatch';
    case Sha256Mismatch = 'sha256_mismatch';
}
