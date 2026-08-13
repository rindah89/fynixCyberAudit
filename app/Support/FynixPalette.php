<?php

namespace App\Support;

/**
 * Fynix suite palette for Filament Color:: arrays.
 * Hex values match design.md / resources/css/tokens.css.
 */
final class FynixPalette
{
    /** @return array<int, string> */
    public static function primary(): array
    {
        return [
            50 => '#f0faf7',
            100 => '#e9fef0',
            200 => '#c9fdd9',
            300 => '#75fc96',
            400 => '#4ae87a',
            500 => '#17a94c',
            600 => '#17a94c',
            700 => '#0c6b2f',
            800 => '#132d28',
            900 => '#0a0a0a',
            950 => '#0a0a0a',
        ];
    }

    /** @return array<int, string> */
    public static function danger(): array
    {
        return [
            50 => '#fdece7',
            100 => '#f8d4c8',
            200 => '#f2b5a6',
            300 => '#e88a70',
            400 => '#dc5a38',
            500 => '#d13817',
            600 => '#d13817',
            700 => '#a82c12',
            800 => '#7a200d',
            900 => '#4d1408',
            950 => '#2a0b04',
        ];
    }

    /** @return array<int, string> */
    public static function warning(): array
    {
        return [
            50 => '#fff4e0',
            100 => '#ffe8b8',
            200 => '#ffd9a0',
            300 => '#f5c46a',
            400 => '#d4922a',
            500 => '#b96a00',
            600 => '#b96a00',
            700 => '#8a5000',
            800 => '#5c3500',
            900 => '#3d2300',
            950 => '#241500',
        ];
    }

    /** @return array<int, string> */
    public static function info(): array
    {
        return [
            50 => '#ebf1fe',
            100 => '#d4e0fc',
            200 => '#a9c1f8',
            300 => '#7a9ef0',
            400 => '#4b7ae8',
            500 => '#2563eb',
            600 => '#2563eb',
            700 => '#1d4ed8',
            800 => '#1e3a8a',
            900 => '#172554',
            950 => '#0b1226',
        ];
    }

    /** @return array<int, string> */
    public static function success(): array
    {
        return [
            50 => '#e9fef0',
            100 => '#c9fdd9',
            200 => '#9ef0b8',
            300 => '#4ae87a',
            400 => '#2dc85e',
            500 => '#17a94c',
            600 => '#17a94c',
            700 => '#0c6b2f',
            800 => '#0a5425',
            900 => '#132d28',
            950 => '#0a0a0a',
        ];
    }

    /** @return array<string, array<int, string>> */
    public static function filamentColors(): array
    {
        return [
            'primary' => self::primary(),
            'danger' => self::danger(),
            'warning' => self::warning(),
            'info' => self::info(),
            'success' => self::success(),
        ];
    }
}
