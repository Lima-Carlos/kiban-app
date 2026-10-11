<?php

namespace App\Licensing;

enum LicenseState: string
{
    /** Signature and dates checked. */
    case Valid = 'valid';

    /** The app never received a license for this company. */
    case Missing = 'missing';

    /** The last license expired (the Core was unreachable for too long). */
    case Expired = 'expired';

    /** Signature does not match: the license was changed or did not come from the Core. */
    case Invalid = 'invalid';
}
