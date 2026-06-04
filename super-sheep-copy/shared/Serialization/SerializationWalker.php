<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Serialization;

final class SerializationWalker implements SerializationWalkerInterface
{
    public function walk($value, callable $string_replacer)
    {
        if (is_string($value)) {
            return $string_replacer($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->walk($item, $string_replacer);
            }

            return $value;
        }

        if (is_object($value)) {
            if ($value instanceof \__PHP_Incomplete_Class) {
                return $value;
            }

            foreach (get_object_vars($value) as $property => $item) {
                $value->{$property} = $this->walk($item, $string_replacer);
            }
        }

        return $value;
    }
}
