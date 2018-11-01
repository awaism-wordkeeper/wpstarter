<?php declare(strict_types=1);
/*
 * This file is part of the WP Starter package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WeCodeMore\WpStarter\Env;

/**
 * Filters to set assign proper types to env variables.
 *
 * Env variables are always strings. WP Starter uses env variables to store WordPress configuration
 * constants, but those might need to be set with different types. E.g an env var "false" needs to
 * be set as _boolean_ `false`, even because PHP evaluates "false" string as true.
 *
 * This class provides a way to filter variable and assign proper type. This class is not aware of
 * the "conversion strategy" to use, that is passed beside the input value to class methods.
 * The strategy to use is defined in the `WordPressEnvBridge` class constants.
 *
 * phpcs:disable Inpsyde.CodeQuality.ArgumentTypeDeclaration
 * phpcs:disable Inpsyde.CodeQuality.ReturnTypeDeclaration
 */
final class Filters
{
    const FILTER_BOOL = 'bool';
    const FILTER_INT = 'int';
    const FILTER_INT_OR_BOOL = 'int|bool';
    const FILTER_STRING = 'string';
    const FILTER_OCTAL_MOD = 'mod';

    /**
     * Return given value filtered based on "mode".
     *
     * @param string $mode One of the `FILTER_*` class constants.
     * @param mixed $value
     * @return int|bool|string|null
     */
    public function filter(string $mode, $value)
    {
        try {
            return $this->applyFilter($mode, $value);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * @param string $mode
     * @param mixed $value
     * @return int|bool|string|null
     */
    private function applyFilter(string $mode, $value)
    {
        switch ($mode) {
            case self::FILTER_BOOL:
                return $this->filterBool($value);
            case self::FILTER_INT:
                return $this->filterInt($value);
            case self::FILTER_STRING:
                return $this->filterString($value);
            case self::FILTER_INT_OR_BOOL:
                return $this->filterIntOrBool($value);
            case self::FILTER_OCTAL_MOD:
                return $this->filterOctalMod($value);
        }

        return null;
    }

    /**
     * @param bool|int|string $value
     * @return bool
     */
    private function filterBool($value): bool
    {
        if (in_array($value, ['', null], true)) {
            throw new \Exception('Invalid bool.');
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new \Exception('Invalid bool.');
        }

        return (bool)$bool;
    }

    /**
     * @param int|float|bool $value
     * @return int
     */
    private function filterInt($value): int
    {
        if (!is_numeric($value) && !is_bool($value)) {
            throw new \Exception('Invalid integer.');
        }

        return (int)$value;
    }

    /**
     * @param string|int|float|bool $value
     * @return string
     */
    private function filterString($value): string
    {
        if (!is_scalar($value)) {
            throw new \Exception('Invalid integer.');
        }

        return (string)filter_var($value, FILTER_SANITIZE_STRING);
    }

    /**
     * @param int|float|bool|string value
     * @return bool|int
     */
    private function filterIntOrBool($value)
    {
        return is_numeric($value) ? $this->filterInt($value) : $this->filterBool($value);
    }

    /**
     * @param int|string $value
     * @return int
     */
    private function filterOctalMod($value): int
    {
        if (is_int($value) && ($value >= 0) && ($value <= 0777)) {
            return $value;
        }

        if (!is_string($value) || !is_numeric($value)) {
            throw new \Exception('Invalid octal mod.');
        }

        return (int)octdec($value);
    }
}