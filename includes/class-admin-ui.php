<?php
/**
 * Presentation helpers for the Fatal Plugin Auto Deactivator admin screens.
 *
 * This class owns the *markup vocabulary* of the admin UI: icons, buttons,
 * badges, chips, panels, stat cards and empty states. FPAD_Admin owns the data
 * and composes these pieces, so the two concerns stay separable and the class
 * list stays in one place. See docs/ui.md for the design system.
 *
 * Every helper escapes its own arguments and returns a string, so callers can
 * echo the result directly.
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Admin_UI
 */
class FPAD_Admin_UI {

	/**
	 * Inline SVG icon set.
	 *
	 * Icons are inlined rather than loaded as files so they inherit currentColor
	 * and never cost an extra request. Each entry is the inner markup of a
	 * 24x24 stroke icon.
	 *
	 * @return array
	 */
	private static function icon_paths() {
		return array(
			'shield-check' => '<path d="M12 3l7 3v5.5c0 4.4-3 7.4-7 9.5-4-2.1-7-5.1-7-9.5V6z"/><path d="M9 12l2 2 4-4"/>',
			'shield-alert' => '<path d="M12 3l7 3v5.5c0 4.4-3 7.4-7 9.5-4-2.1-7-5.1-7-9.5V6z"/><path d="M12 8v4"/><path d="M12 15.5h.01"/>',
			'alert'        => '<path d="M10.3 4.3 2.6 18a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'activity'     => '<path d="M3 12h4l3 8 4-16 3 8h4"/>',
			'power'        => '<path d="M12 3v9"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/>',
			'plug'         => '<path d="M9 3v6"/><path d="M15 3v6"/><path d="M7 9h10v4a5 5 0 0 1-10 0z"/><path d="M12 18v3"/>',
			'help'         => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.4 2.3c-.6.3-.9.8-.9 1.4v.3"/><path d="M12 17h.01"/>',
			'clock'        => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'file'         => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
			'link'         => '<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>',
			'search'       => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'filter'       => '<path d="M4 5h16l-6.5 7.5V19l-3 2v-8.5z"/>',
			'download'     => '<path d="M12 4v10"/><path d="m8 12 4 4 4-4"/><path d="M5 19h14"/>',
			'trash'        => '<path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="m6 7 1 13h10l1-13"/>',
			'copy'         => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v1"/>',
			'bell'         => '<path d="M6 9a6 6 0 1 1 12 0c0 4 2 5 2 5H4s2-1 2-5z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
			'mail'         => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
			'globe'        => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18Z"/>',
			'sliders'      => '<path d="M4 7h10"/><path d="M18 7h2"/><path d="M4 17h4"/><path d="M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
			'refresh'      => '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/>',
			'list'         => '<path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
			'calendar'     => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
			'cpu'          => '<rect x="7" y="7" width="10" height="10" rx="2"/><path d="M10 3v2"/><path d="M14 3v2"/><path d="M10 19v2"/><path d="M14 19v2"/><path d="M3 10h2"/><path d="M3 14h2"/><path d="M19 10h2"/><path d="M19 14h2"/>',
			'x'            => '<path d="m6 6 12 12"/><path d="m18 6-12 12"/>',
			'chevron'      => '<path d="m6 9 6 6 6-6"/>',
			'ban'          => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
			'check'        => '<path d="m5 12 5 5 9-11"/>',
			'send'         => '<path d="m21 3-9 18-2.5-7.5L2 11z"/>',
		);
	}

	/**
	 * Render an inline SVG icon.
	 *
	 * @param string $name  Icon key from icon_paths().
	 * @param string $class Extra classes for the <svg> element.
	 * @return string
	 */
	public static function icon( $name, $class = '' ) {
		$icons = self::icon_paths();
		if ( ! isset( $icons[ $name ] ) ) {
			return '';
		}

		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. $icons[ $name ] // Static markup from icon_paths(), no user input.
			. '</svg>';
	}

	/**
	 * Render a pill badge.
	 *
	 * @param string $label   Visible text.
	 * @param string $variant One of: ok, warn, danger, info, neutral, source.
	 * @param string $icon    Optional icon key.
	 * @return string
	 */
	public static function badge( $label, $variant = 'neutral', $icon = '' ) {
		$allowed = array( 'ok', 'warn', 'danger', 'info', 'neutral', 'source' );
		if ( ! in_array( $variant, $allowed, true ) ) {
			$variant = 'neutral';
		}

		return '<span class="fpad-badge fpad-badge--' . esc_attr( $variant ) . '">'
			. ( $icon ? self::icon( $icon, 'fpad:h-3 fpad:w-3' ) : '' )
			. esc_html( $label )
			. '</span>';
	}

	/**
	 * The plugin's logo mark: a white shield with a power symbol, on the brand tile.
	 *
	 * Inline SVG rather than an <img> so it costs no request and stays crisp at
	 * any size. This is a sync pair with `.wordpress-org/src/icon.svg`, the source
	 * the WordPress.org icon PNGs are rendered from: change one, change the other,
	 * or the plugin page and the admin screen stop looking like the same product.
	 * Gradient ids are prefixed because wp-admin pages carry other plugins' SVGs.
	 *
	 * @param int $size Rendered edge length in pixels.
	 * @return string
	 */
	public static function logo( $size = 40 ) {
		$size = (int) $size;

		return '<svg class="fpad-logo" width="' . esc_attr( $size ) . '" height="' . esc_attr( $size ) . '" viewBox="0 0 256 256" role="img" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">'
			. '<defs>'
			. '<linearGradient id="fpad-logo-tile" x1="0" y1="0" x2="0.6" y2="1">'
			. '<stop offset="0" stop-color="#3a94d4"/><stop offset="0.55" stop-color="#2271b1"/><stop offset="1" stop-color="#144a76"/>'
			. '</linearGradient>'
			. '<radialGradient id="fpad-logo-glow" cx="0.22" cy="0.14" r="0.85">'
			. '<stop offset="0" stop-color="#ffffff" stop-opacity="0.22"/><stop offset="0.55" stop-color="#ffffff" stop-opacity="0.05"/><stop offset="1" stop-color="#ffffff" stop-opacity="0"/>'
			. '</radialGradient>'
			. '<linearGradient id="fpad-logo-shield" x1="0.2" y1="0" x2="0.8" y2="1">'
			. '<stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#e8f1f9"/>'
			. '</linearGradient>'
			. '</defs>'
			. '<rect width="256" height="256" rx="56" fill="url(#fpad-logo-tile)"/>'
			. '<rect width="256" height="256" rx="56" fill="url(#fpad-logo-glow)"/>'
			. '<g transform="translate(20 12) scale(9)">'
			. '<path d="M12 3l7 3v5.5c0 4.4-3 7.4-7 9.5-4-2.1-7-5.1-7-9.5V6z" fill="url(#fpad-logo-shield)"/>'
			. '</g>'
			. '<g transform="translate(72.8 54) scale(4.6)" fill="none" stroke="#1b5e94" stroke-width="2.1" stroke-linecap="round">'
			. '<path d="M12 3.4v8.2"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/>'
			. '</g>'
			. '</svg>';
	}

	/**
	 * Icon tile that anchors a log entry card.
	 *
	 * Two signals in one square: the glyph says where the crash came from
	 * (plugin, theme, drop-in…), the tint says what the plugin did about it —
	 * the same colour vocabulary the status badge beside it uses.
	 *
	 * @param string $icon    Icon key from icon_paths().
	 * @param string $variant One of: ok, warn, danger, info, neutral.
	 * @return string
	 */
	public static function entry_mark( $icon, $variant = 'neutral' ) {
		$class = 'fpad-entry-mark';
		if ( in_array( $variant, array( 'ok', 'warn', 'danger', 'info' ), true ) ) {
			$class .= ' fpad-entry-mark--' . $variant;
		}

		return '<span class="' . esc_attr( $class ) . '" aria-hidden="true">' . self::icon( $icon ) . '</span>';
	}

	/**
	 * Render a button or button-styled link.
	 *
	 * @param array $args {
	 *     @type string $label   Visible text (required).
	 *     @type string $href    Render an <a> when set, a <button> otherwise.
	 *     @type string $variant default|primary|danger|ghost.
	 *     @type string $size    md|sm.
	 *     @type string $icon    Optional icon key.
	 *     @type string $type    Button type attribute (default "button").
	 *     @type string $name    Button name attribute.
	 *     @type string $value   Button value attribute.
	 *     @type array  $attrs   Extra HTML attributes as name => value.
	 * }
	 * @return string
	 */
	public static function button( $args ) {
		$args = array_merge(
			array(
				'label'   => '',
				'href'    => '',
				'variant' => 'default',
				'size'    => 'md',
				'icon'    => '',
				'type'    => 'button',
				'name'    => '',
				'value'   => '',
				'attrs'   => array(),
			),
			$args
		);

		$classes = array( 'fpad-btn' );
		if ( in_array( $args['variant'], array( 'primary', 'danger', 'ghost' ), true ) ) {
			$classes[] = 'fpad-btn--' . $args['variant'];
		}
		if ( 'sm' === $args['size'] ) {
			$classes[] = 'fpad-btn--sm';
		}
		if ( ! empty( $args['attrs']['class'] ) ) {
			$classes[] = $args['attrs']['class'];
			unset( $args['attrs']['class'] );
		}

		$attributes = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		foreach ( $args['attrs'] as $name => $value ) {
			$attributes .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		$inner = ( $args['icon'] ? self::icon( $args['icon'] ) : '' ) . '<span>' . esc_html( $args['label'] ) . '</span>';

		if ( '' !== $args['href'] ) {
			return '<a href="' . esc_url( $args['href'] ) . '"' . $attributes . '>' . $inner . '</a>';
		}

		$extra = ' type="' . esc_attr( $args['type'] ) . '"';
		if ( '' !== $args['name'] ) {
			$extra .= ' name="' . esc_attr( $args['name'] ) . '"';
		}
		if ( '' !== $args['value'] ) {
			$extra .= ' value="' . esc_attr( $args['value'] ) . '"';
		}

		return '<button' . $extra . $attributes . '>' . $inner . '</button>';
	}

	/**
	 * Render a small labelled metadata chip.
	 *
	 * @param string $icon  Icon key.
	 * @param string $label Screen-reader/context label.
	 * @param string $value Value text.
	 * @return string
	 */
	public static function chip( $icon, $label, $value ) {
		return '<span class="fpad-chip" title="' . esc_attr( $label . ': ' . $value ) . '">'
			. self::icon( $icon )
			. '<span class="fpad-sr-only">' . esc_html( $label ) . '</span>'
			. '<span class="fpad-chip-value">' . esc_html( $value ) . '</span>'
			. '</span>';
	}

	/**
	 * Render a stat card.
	 *
	 * @param array $args {
	 *     @type string $value   Formatted value.
	 *     @type string $label   Caption.
	 *     @type string $icon    Icon key.
	 *     @type string $variant brand|ok|warn|danger.
	 * }
	 * @return string
	 */
	public static function stat( $args ) {
		$args = array_merge(
			array(
				'value'   => '',
				'label'   => '',
				'icon'    => 'activity',
				'variant' => 'brand',
			),
			$args
		);

		$class = 'fpad-stat';
		if ( in_array( $args['variant'], array( 'ok', 'warn', 'danger' ), true ) ) {
			$class .= ' fpad-stat--' . $args['variant'];
		}

		return '<div class="' . esc_attr( $class ) . '">'
			. '<span class="fpad-stat-icon">' . self::icon( $args['icon'] ) . '</span>'
			. '<span>'
			. '<span class="fpad-stat-value">' . esc_html( $args['value'] ) . '</span>'
			. '<span class="fpad-stat-label">' . esc_html( $args['label'] ) . '</span>'
			. '</span>'
			. '</div>';
	}

	/**
	 * Render an empty-state block.
	 *
	 * @param array $args {
	 *     @type string $title   Headline.
	 *     @type string $text    Supporting copy.
	 *     @type string $icon    Icon key.
	 *     @type string $actions Pre-rendered action markup.
	 * }
	 * @return string
	 */
	public static function empty_state( $args ) {
		$args = array_merge(
			array(
				'title'   => '',
				'text'    => '',
				'icon'    => 'shield-check',
				'actions' => '',
			),
			$args
		);

		return '<div class="fpad-empty">'
			. '<span class="fpad-empty-icon">' . self::icon( $args['icon'] ) . '</span>'
			. '<p class="fpad-empty-title">' . esc_html( $args['title'] ) . '</p>'
			. '<p class="fpad-empty-text">' . esc_html( $args['text'] ) . '</p>'
			. ( $args['actions'] ? '<div class="fpad:mt-2 fpad:flex fpad:gap-2">' . $args['actions'] . '</div>' : '' )
			. '</div>';
	}

	/**
	 * Open a panel and render its header.
	 *
	 * @param array $args {
	 *     @type string $title   Panel title.
	 *     @type string $desc    Optional description.
	 *     @type string $icon    Optional icon key.
	 *     @type string $actions Pre-rendered header actions.
	 *     @type bool   $flush   True to drop the body padding (for lists/tables).
	 * }
	 * @return string
	 */
	public static function panel_open( $args ) {
		$args = array_merge(
			array(
				'title'   => '',
				'desc'    => '',
				'icon'    => '',
				'actions' => '',
				'flush'   => false,
			),
			$args
		);

		$html = '<section class="fpad-panel">';

		if ( '' !== $args['title'] || '' !== $args['actions'] ) {
			$html .= '<div class="fpad-panel-head"><div>';
			$html .= '<h2 class="fpad-panel-title">';
			if ( $args['icon'] ) {
				$html .= '<span class="fpad:mr-1.5 fpad:inline-block fpad:align-[-3px] fpad:text-ink-400">' . self::icon( $args['icon'], 'fpad:h-4 fpad:w-4' ) . '</span>';
			}
			$html .= esc_html( $args['title'] ) . '</h2>';
			if ( '' !== $args['desc'] ) {
				$html .= '<p class="fpad-panel-desc">' . esc_html( $args['desc'] ) . '</p>';
			}
			$html .= '</div>';
			if ( '' !== $args['actions'] ) {
				$html .= '<div class="fpad-masthead-actions">' . $args['actions'] . '</div>';
			}
			$html .= '</div>';
		}

		$html .= '<div class="fpad-panel-body' . ( $args['flush'] ? ' fpad-panel-body-flush' : '' ) . '">';

		return $html;
	}

	/**
	 * Close a panel opened with panel_open().
	 *
	 * @param string $footer Pre-rendered footer markup, or '' for no footer.
	 * @return string
	 */
	public static function panel_close( $footer = '' ) {
		return '</div>' . ( '' !== $footer ? '<div class="fpad-panel-foot">' . $footer . '</div>' : '' ) . '</section>';
	}

	/**
	 * Render a settings row: label + help text on the left, control on the right.
	 *
	 * @param array $args {
	 *     @type string $label   Row label.
	 *     @type string $help    Help text.
	 *     @type string $control Pre-rendered control markup.
	 *     @type bool   $stacked True to place the control below the label (wide controls).
	 * }
	 * @return string
	 */
	public static function setting_row( $args ) {
		$args = array_merge(
			array(
				'label'   => '',
				'help'    => '',
				'control' => '',
				'stacked' => false,
			),
			$args
		);

		$html = '<div class="fpad-setting' . ( $args['stacked'] ? ' fpad:flex-col' : '' ) . '">';
		$html .= '<div class="fpad-setting-body">';
		$html .= '<p class="fpad-setting-label">' . esc_html( $args['label'] ) . '</p>';
		if ( '' !== $args['help'] ) {
			$html .= '<p class="fpad-help">' . esc_html( $args['help'] ) . '</p>';
		}
		$html .= '</div>';
		$html .= '<div class="' . ( $args['stacked'] ? 'fpad:w-full' : 'fpad-setting-control' ) . '">' . $args['control'] . '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render an on/off switch bound to a checkbox.
	 *
	 * @param array $args {
	 *     @type string $name    Field name.
	 *     @type bool   $checked Current state.
	 *     @type string $text    Text shown next to the switch.
	 *     @type array  $attrs   Extra attributes for the input.
	 * }
	 * @return string
	 */
	public static function switch_control( $args ) {
		$args = array_merge(
			array(
				'name'    => '',
				'checked' => false,
				'text'    => '',
				'attrs'   => array(),
			),
			$args
		);

		$attributes = '';
		foreach ( $args['attrs'] as $name => $value ) {
			$attributes .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		return '<label class="fpad-switch">'
			. '<input type="checkbox" name="' . esc_attr( $args['name'] ) . '" value="1"' . checked( $args['checked'], true, false ) . $attributes . '>'
			. '<span class="fpad-switch-track"></span>'
			. '<span class="fpad-switch-text">' . esc_html( $args['text'] ) . '</span>'
			. '</label>';
	}

	/**
	 * Render a checkbox styled as a selectable card.
	 *
	 * @param string $name    Field name (usually an array field).
	 * @param string $value   Checkbox value.
	 * @param string $label   Visible label.
	 * @param bool   $checked Current state.
	 * @return string
	 */
	public static function check_card( $name, $value, $label, $checked ) {
		return '<label class="fpad-check" data-fpad-filterable="' . esc_attr( strtolower( $label . ' ' . $value ) ) . '">'
			. '<input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $checked, true, false ) . '>'
			. '<span class="fpad-check-text" title="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</span>'
			. '</label>';
	}

	/**
	 * Render a <select> from a key => label map.
	 *
	 * @param string $name     Field name.
	 * @param array  $options  key => label.
	 * @param string $selected Selected key.
	 * @param string $any      Label for the empty "all" option, or '' to omit it.
	 * @return string
	 */
	public static function select( $name, $options, $selected, $any = '' ) {
		$html = '<select class="fpad-select" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		if ( '' !== $any ) {
			$html .= '<option value=""' . selected( $selected, '', false ) . '>' . esc_html( $any ) . '</option>';
		}
		foreach ( $options as $key => $label ) {
			$html .= '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';

		return $html;
	}
}
