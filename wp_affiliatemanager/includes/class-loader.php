<?php
/**
 * Loader / Registro central de hooks.
 *
 * Actúa como el "bus de eventos" del plugin:
 * recoge acciones y filtros de todos los módulos y los registra en WordPress
 * en un único punto, facilitando debugging y testing.
 *
 * @package WP_AffiliateManager
 * @since   1.0.0
 */

namespace WP_AffiliateManager;

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Loader
 *
 * Almacena y registra todos los hooks (actions y filters) del plugin.
 * Los módulos individuales llaman a add_action() / add_filter() en el Loader,
 * que los registra en WordPress al llamar a run().
 *
 * @since 1.0.0
 */
class Loader {

	/**
	 * Colección de acciones registradas.
	 *
	 * @since  1.0.0
	 * @var    array<int, array{hook: string, component: object, callback: string, priority: int, accepted_args: int}>
	 */
	private array $actions = array();

	/**
	 * Colección de filtros registrados.
	 *
	 * @since  1.0.0
	 * @var    array<int, array{hook: string, component: object, callback: string, priority: int, accepted_args: int}>
	 */
	private array $filters = array();

	/**
	 * Encola una acción de WordPress para ser registrada al ejecutar run().
	 *
	 * @since  1.0.0
	 * @param  string $hook          Nombre del hook de WordPress.
	 * @param  object $component     Instancia del objeto que contiene el callback.
	 * @param  string $callback      Nombre del método del objeto.
	 * @param  int    $priority      Prioridad del hook. Default: 10.
	 * @param  int    $accepted_args Número de argumentos aceptados. Default: 1.
	 * @return void
	 */
	public function add_action(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->actions[] = $this->build_hook_entry( $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Encola un filtro de WordPress para ser registrado al ejecutar run().
	 *
	 * @since  1.0.0
	 * @param  string $hook          Nombre del hook de WordPress.
	 * @param  object $component     Instancia del objeto que contiene el callback.
	 * @param  string $callback      Nombre del método del objeto.
	 * @param  int    $priority      Prioridad del hook. Default: 10.
	 * @param  int    $accepted_args Número de argumentos aceptados. Default: 1.
	 * @return void
	 */
	public function add_filter(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->filters[] = $this->build_hook_entry( $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Registra todos los hooks encolados en WordPress.
	 *
	 * Se llama una única vez desde Plugin::run().
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function run(): void {
		foreach ( $this->filters as $hook ) {
			add_filter(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}

		foreach ( $this->actions as $hook ) {
			add_action(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}
	}

	/**
	 * Construye un array estandarizado para un hook.
	 *
	 * @since  1.0.0
	 * @param  string $hook
	 * @param  object $component
	 * @param  string $callback
	 * @param  int    $priority
	 * @param  int    $accepted_args
	 * @return array{hook: string, component: object, callback: string, priority: int, accepted_args: int}
	 */
	private function build_hook_entry(
		string $hook,
		object $component,
		string $callback,
		int $priority,
		int $accepted_args
	): array {
		return array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}
}
