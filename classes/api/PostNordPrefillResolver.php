<?php

declare(strict_types=1);

namespace Logingrupa\PostNordShippingShopaholic\Classes\Api;

use Illuminate\Support\Facades\Session;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\UserAddress;

/**
 * Class PostNordPrefillResolver
 * @package Logingrupa\PostNordShippingShopaholic\Classes\Api
 *
 * Resolves checkout prefill postcode, street, and city for logged-in users
 * (UserAddress shipping -> billing -> latest Order) with a session fallback.
 */
class PostNordPrefillResolver
{
    public const SESSION_LAST_POSTAL_CODE_KEY = 'postnord_last_postal_code';

    /**
     * Resolve prefill postcode, street, and city for the active shopper.
     *
     * @return array{postcode: string, street: string, city: string}
     */
    public static function resolve(?object $obUser = null): array
    {
        $iUserId = self::resolveUserId($obUser);
        if ($iUserId > 0) {
            $arFromAddress = self::readUserAddressPrefill($iUserId);
            if ($arFromAddress['postcode'] !== '') {
                return $arFromAddress;
            }

            $arFromOrder = self::readLatestOrderPrefill($iUserId);
            if ($arFromOrder['postcode'] !== '') {
                return $arFromOrder;
            }
        }

        $mSessionZip = Session::get(self::SESSION_LAST_POSTAL_CODE_KEY, '');

        return [
            'postcode' => is_string($mSessionZip) ? trim($mSessionZip) : '',
            'street'   => '',
            'city'     => '',
        ];
    }

    /**
     * Resolve the authenticated user ID from the passed user object or Auth facade.
     */
    private static function resolveUserId(?object $obUser): int
    {
        if (is_object($obUser)) {
            /** @var mixed $mUserId */
            $mUserId = $obUser->id ?? null;

            return is_numeric($mUserId) ? (int) $mUserId : 0;
        }

        try {
            if (!class_exists(\Auth::class)) {
                return 0;
            }

            $obAuthUser = \Auth::getUser();
            if (!is_object($obAuthUser)) {
                return 0;
            }

            /** @var mixed $mAuthId */
            $mAuthId = $obAuthUser->id ?? null;

            return is_numeric($mAuthId) ? (int) $mAuthId : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Read postcode/street/city from the user's saved shipping or billing UserAddress.
     *
     * @return array{postcode: string, street: string, city: string}
     */
    private static function readUserAddressPrefill(int $iUserId): array
    {
        $arEmpty = ['postcode' => '', 'street' => '', 'city' => ''];

        try {
            $obList = UserAddress::where('user_id', $iUserId)
                ->orderByRaw("CASE WHEN type = 'shipping' THEN 0 ELSE 1 END")
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            foreach ($obList as $obAddress) {
                $arRow = $obAddress instanceof UserAddress ? self::extractAddressRow($obAddress) : null;
                if ($arRow !== null) {
                    return $arRow;
                }
            }
        } catch (\Throwable) {
            return $arEmpty;
        }

        return $arEmpty;
    }

    /**
     * Extract non-empty prefill data from a UserAddress row.
     *
     * @return array{postcode: string, street: string, city: string}|null
     */
    private static function extractAddressRow(UserAddress $obAddress): ?array
    {
        $sPostcode = trim((string) $obAddress->postcode);
        if ($sPostcode === '') {
            return null;
        }

        return [
            'postcode' => $sPostcode,
            'street'   => trim((string) $obAddress->address1),
            'city'     => trim((string) $obAddress->city),
        ];
    }

    /**
     * Fallback prefill from the logged-in user's most recent order properties.
     *
     * @return array{postcode: string, street: string, city: string}
     */
    private static function readLatestOrderPrefill(int $iUserId): array
    {
        $arEmpty = ['postcode' => '', 'street' => '', 'city' => ''];

        try {
            $obOrder = Order::where('user_id', $iUserId)->orderByDesc('id')->first();
            if (!$obOrder instanceof Order) {
                return $arEmpty;
            }

            /** @var mixed $mProps */
            $mProps = $obOrder->property;
            $arProps = is_array($mProps) ? $mProps : [];

            return [
                'postcode' => self::firstNonEmptyString([$arProps['shipping_postcode'] ?? '', $arProps['billing_postcode'] ?? '']),
                'street'   => self::firstNonEmptyString([$arProps['shipping_address1'] ?? '', $arProps['billing_address1'] ?? '']),
                'city'     => self::firstNonEmptyString([$arProps['shipping_city'] ?? '', $arProps['billing_city'] ?? '']),
            ];
        } catch (\Throwable) {
            return $arEmpty;
        }
    }

    /**
     * Return the first non-empty trimmed string from a list of candidates.
     *
     * @param list<mixed> $arCandidates
     */
    private static function firstNonEmptyString(array $arCandidates): string
    {
        foreach ($arCandidates as $mCandidate) {
            if (is_string($mCandidate) && trim($mCandidate) !== '') {
                return trim($mCandidate);
            }
        }

        return '';
    }
}
