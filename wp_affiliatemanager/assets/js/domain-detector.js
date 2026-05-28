/**
 * WPAMDomainDetector — módulo compartido de detección por dominio.
 *
 * Expuesto como window.WPAMDomainDetector para ser reutilizado por:
 *   - post-links.js     (metabox del editor de post)
 *   - post-affiliates.js (board Post Affiliates)
 *
 * Espeja exactamente la lógica PHP de:
 *   - wpam_normalize_domain()
 *   - wpam_extract_domain_from_url()
 *   - Repository::find_by_domain()
 *
 * Reglas de matching:
 *   - Exacto:  "amazon.com"     === "amazon.com"      → match
 *   - Sufijo:  "shop.genki.com" ends with ".genki.com" → match
 *   - NO:      "amazon.com.mx"  ends with ".amazon.com"? → NO match
 *
 * @package WP_AffiliateManager
 * @since   0.1.4
 */

window.WPAMDomainDetector = {

	/**
	 * Normaliza un dominio: lowercase, sin www., sin protocolo, sin trailing slash.
	 * Espejo de wpam_normalize_domain() en PHP.
	 *
	 * @param  {string} input Dominio o URL.
	 * @return {string}
	 */
	normalizeDomain( input ) {
		input = String( input ).trim().toLowerCase().replace( /[/,'"]+$/, '' ).trim();
		if ( ! input ) { return ''; }

		if ( input.includes( '://' ) ) {
			try {
				input = new URL( input ).hostname;
			} catch ( _ ) {
				return '';
			}
		}

		if ( input.startsWith( 'www.' ) ) {
			input = input.slice( 4 );
		}

		return input.replace( /[/.]+$/, '' );
	},

	/**
	 * Extrae y normaliza el dominio de una URL completa.
	 * Espejo de wpam_extract_domain_from_url() en PHP.
	 *
	 * @param  {string} url
	 * @return {string} Dominio normalizado o '' si la URL es inválida.
	 */
	extractDomain( url ) {
		try {
			const parsed = new URL( String( url ).trim() );
			return this.normalizeDomain( parsed.hostname );
		} catch ( _ ) {
			return '';
		}
	},

	/**
	 * Busca el primer afiliado cuya lista `domains` coincida con el dominio dado.
	 * Espejo de Repository::find_by_domain() en PHP.
	 *
	 * @param  {string} domain    Dominio ya normalizado.
	 * @param  {Array}  affiliates Lista de afiliados con campo `domains` (array de strings normalizados).
	 * @return {Object|null}
	 */
	findByDomain( domain, affiliates ) {
		if ( ! domain || ! Array.isArray( affiliates ) ) { return null; }

		for ( const aff of affiliates ) {
			const domains = Array.isArray( aff.domains ) ? aff.domains : [];

			for ( const affDomain of domains ) {
				if ( domain === affDomain ) { return aff; }
				if ( domain.endsWith( '.' + affDomain ) ) { return aff; }
			}
		}

		return null;
	},
};
