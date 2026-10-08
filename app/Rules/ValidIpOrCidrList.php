<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a list of IPv4/IPv6 addresses and CIDR ranges separated by commas, spaces, or new lines.
 *
 * Unlike ValidIpOrCidr, no entry is treated as a wildcard: every entry must be a real address or range.
 */
class ValidIpOrCidrList implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('Enter IP addresses or CIDR ranges as text.'));

            return;
        }

        $invalid = array_values(array_filter(
            parseNetworkRanges($value),
            fn (string $range): bool => ! isValidIpOrCidr($range),
        ));

        if ($invalid !== []) {
            $fail(__('Not an IP address or CIDR range: :ranges', ['ranges' => implode(', ', $invalid)]));
        }
    }
}
