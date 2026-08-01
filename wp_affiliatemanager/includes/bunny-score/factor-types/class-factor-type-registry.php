<?php
/**
 * Bunny Score — Factor Type registry.
 *
 * Espejo exacto de `Frontend\Layouts\Layout_Registry`: punto único de
 * descubrimiento de tipos de factor. Agregar un tipo nuevo en el futuro es
 * implementar `Factor_Type_Interface` + registrarlo aquí (o vía el filtro),
 * sin tocar `Bunny_Score_Factors`, `Bunny_Score_Manager`, `Bunny_Score_Admin`
 * ni `Bunny_Score_Screen`.
 *
 * @package WP_AffiliateManager\Bunny_Score\Factor_Types
 * @since   1.7.5
 */

namespace WP_AffiliateManager\Bunny_Score\Factor_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Factor_Type_Registry {

    public const DEFAULT_TYPE = 'boolean';

    /** @var array<string, Factor_Type_Interface>|null */
    private static ?array $types = null;

    /**
     * @since 1.7.5
     * @return array<string, Factor_Type_Interface>
     */
    public static function get_types(): array {
        if ( null !== self::$types ) {
            return self::$types;
        }

        $types = array(
            new Factor_Type_Boolean(),
            new Factor_Type_Numeric(),
            new Factor_Type_Label(),
            new Factor_Type_Range_Table(),
        );

        /**
         * Permite registrar tipos de factor adicionales sin tocar el core.
         *
         * @since 1.7.5
         * @param Factor_Type_Interface[] $types
         */
        $types = apply_filters( 'wpam_bunny_score_factor_types', $types );

        $indexed = array();
        foreach ( $types as $type ) {
            if ( $type instanceof Factor_Type_Interface ) {
                $indexed[ $type->get_id() ] = $type;
            }
        }

        self::$types = $indexed;
        return self::$types;
    }

    /**
     * @since 1.7.5
     * @param string $id
     * @return Factor_Type_Interface
     */
    public static function get( string $id ): Factor_Type_Interface {
        $types = self::get_types();
        return $types[ $id ] ?? $types[ self::DEFAULT_TYPE ];
    }

    /**
     * @since 1.7.5
     * @return string[]
     */
    public static function get_ids(): array {
        return array_keys( self::get_types() );
    }
}
