<?php
/**
 * The [infinite_icon] shortcode.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

namespace InfiniteIcons\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders `[infinite_icon]` (alias `[ii_icon]`).
 *
 * @since 1.0.0
 */
final class Shortcode {

	/**
	 * Primary shortcode tag.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TAG = 'infinite_icon';

	/**
	 * Alias tag.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ALIAS = 'ii_icon';

	/**
	 * Icon renderer.
	 *
	 * @since 1.0.0
	 * @var Icon
	 */
	private $icon;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Icon $icon Icon renderer.
	 */
	public function __construct( Icon $icon ) {
		$this->icon = $icon;
	}

	/**
	 * Registers both shortcode tags.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_shortcode( self::ALIAS, array( $this, 'render' ) );
	}

	/**
	 * Renders the shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @param string|null                  $content Enclosed content; unused.
	 * @param string                       $tag  Shortcode tag.
	 * @return string HTML, or an empty string for an unknown icon.
	 */
	public function render( $atts, ?string $content = null, string $tag = self::TAG ): string {
		$atts = shortcode_atts(
			array(
				'name'   => '',
				'size'   => '24',
				'color'  => '',
				'class'  => '',
				'label'  => '',
				'rotate' => '',
				'flip'   => '',
				'link'   => '',
				'target' => '',
				'align'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			$tag
		);

		$name = trim( (string) $atts['name'] );
		if ( '' === $name || ! $this->icon->exists( $name ) ) {
			return $this->unknown_icon_comment( $name );
		}

		$size = strtolower( trim( (string) $atts['size'] ) );
		$html = $this->icon->render(
			$name,
			array(
				'size'   => 'inherit' === $size ? null : absint( $size ),
				'class'  => (string) $atts['class'],
				'label'  => (string) $atts['label'],
				'color'  => (string) $atts['color'],
				'rotate' => absint( $atts['rotate'] ),
				'flip'   => (string) $atts['flip'],
			)
		);

		if ( '' === $html ) {
			return '';
		}

		$link = esc_url_raw( trim( (string) $atts['link'] ), array( 'http', 'https', 'mailto', 'tel' ) );
		if ( '' !== $link ) {
			$target = '_blank' === trim( (string) $atts['target'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
			$label  = (string) $atts['label'];
			$aria   = '' !== $label ? ' aria-label="' . esc_attr( $label ) . '"' : '';
			$html   = '<a href="' . esc_url( $link ) . '"' . $target . $aria . '>' . $html . '</a>';
		}

		$align = strtolower( trim( (string) $atts['align'] ) );
		if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$this->icon->enqueue_style();
			$html = '<span class="ii-align-' . esc_attr( $align ) . '">' . $html . '</span>';
		}

		return $html;
	}

	/**
	 * Builds the debug comment shown for an unknown icon.
	 *
	 * Nothing is emitted for visitors, so a typo never leaks into the page. An
	 * editor debugging a template with WP_DEBUG on gets an HTML comment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The icon name that was asked for.
	 * @return string
	 */
	private function unknown_icon_comment( string $name ): string {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return sprintf(
			'<!-- infinite-icons: unknown icon %s -->',
			esc_html( str_replace( array( '<', '>', '-->' ), '', $name ) )
		);
	}
}
