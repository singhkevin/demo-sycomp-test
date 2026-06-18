<?php
/**
 * Minimal, dependency-free PDF writer.
 *
 * Generates simple single- or multi-page PDF documents using the two
 * standard Helvetica fonts (no font embedding needed) and embedded JPEG
 * images. Purpose-built for the Sycomp purchase-order document; it is
 * deliberately not a general-purpose PDF library.
 *
 * Coordinates are given in points with the origin at the TOP-left of the
 * page (y grows downward); the class converts to PDF's bottom-left origin.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_PDF.
 */
class Sycomp_B2B_PDF {

	/** A4 page width in points. */
	const PW = 595.28;

	/** A4 page height in points. */
	const PH = 841.89;

	/** Finalized page content streams. */
	protected $pages = array();

	/** Current page content stream buffer. */
	protected $buf = '';

	/** Whether a page is currently open. */
	protected $open = false;

	/** Embedded images: list of array( data, w, h ). */
	protected $images = array();

	/**
	 * Start a new page.
	 */
	public function add_page() {
		if ( $this->open ) {
			$this->pages[] = $this->buf;
		}
		$this->buf  = '';
		$this->open = true;
	}

	/**
	 * Draw a line of text. $top is the text baseline from the page top.
	 *
	 * @param float  $x    Left x.
	 * @param float  $top  Baseline y from top.
	 * @param string $text Text.
	 * @param float  $size Font size.
	 * @param bool   $bold Use Helvetica-Bold.
	 * @param array  $rgb  Fill colour, 0..1 triplet.
	 */
	public function text( $x, $top, $text, $size = 10, $bold = false, $rgb = array( 0, 0, 0 ) ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return;
		}
		$this->buf .= sprintf(
			"BT %s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
			$bold ? '/F2' : '/F1',
			$size,
			$rgb[0], $rgb[1], $rgb[2],
			$x,
			self::PH - $top,
			$this->esc( $text )
		);
	}

	/**
	 * Draw text right-aligned to $x_right.
	 */
	public function text_right( $x_right, $top, $text, $size = 10, $bold = false, $rgb = array( 0, 0, 0 ) ) {
		$this->text( $x_right - $this->width( (string) $text, $size ), $top, $text, $size, $bold, $rgb );
	}

	/**
	 * Draw a straight line.
	 */
	public function line( $x1, $top1, $x2, $top2, $w = 0.6, $rgb = array( 0.86, 0.88, 0.91 ) ) {
		$this->buf .= sprintf(
			"%.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S\n",
			$w, $rgb[0], $rgb[1], $rgb[2],
			$x1, self::PH - $top1,
			$x2, self::PH - $top2
		);
	}

	/**
	 * Draw a filled rectangle. $top is the rectangle's top edge.
	 */
	public function fill_rect( $x, $top, $w, $h, $rgb ) {
		$this->buf .= sprintf(
			"%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
			$rgb[0], $rgb[1], $rgb[2],
			$x, self::PH - $top - $h, $w, $h
		);
	}

	/**
	 * Place an image file, scaled to fit within a bounding box while
	 * preserving its aspect ratio. The image's top-left sits at ($x,$top).
	 *
	 * @param float  $x     Left x.
	 * @param float  $top   Top y.
	 * @param float  $box_w Max width.
	 * @param float  $box_h Max height.
	 * @param string $path  Absolute image file path.
	 * @return array|false  array( drawn_w, drawn_h ) or false on failure.
	 */
	public function image_file( $x, $top, $box_w, $box_h, $path ) {
		$img = self::rasterize( $path );
		if ( ! $img ) {
			return false;
		}
		$scale = min( $box_w / $img['w'], $box_h / $img['h'] );
		if ( $scale <= 0 ) {
			return false;
		}
		$dw = $img['w'] * $scale;
		$dh = $img['h'] * $scale;

		$this->images[] = $img;
		$idx            = count( $this->images );
		$this->buf     .= sprintf(
			"q %.3F 0 0 %.3F %.3F %.3F cm /Im%d Do Q\n",
			$dw, $dh, $x, self::PH - $top - $dh, $idx
		);
		return array( $dw, $dh );
	}

	/**
	 * Load an image file and return it as a white-background JPEG.
	 *
	 * Any GD-readable format (PNG, JPEG, GIF, WEBP) is accepted; the image
	 * is flattened onto white so logos with transparency render correctly.
	 *
	 * @param string $path Absolute file path.
	 * @param int    $max  Maximum dimension in pixels.
	 * @return array|null  array( data, w, h ) or null.
	 */
	public static function rasterize( $path, $max = 760 ) {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! $path || ! is_readable( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return null;
		}
		$src = @imagecreatefromstring( $raw );
		if ( ! $src ) {
			return null;
		}
		$w = imagesx( $src );
		$h = imagesy( $src );
		if ( $w < 1 || $h < 1 ) {
			imagedestroy( $src );
			return null;
		}
		$scale = min( 1.0, $max / max( $w, $h ) );
		$dw    = max( 1, (int) round( $w * $scale ) );
		$dh    = max( 1, (int) round( $h * $scale ) );

		$canvas = imagecreatetruecolor( $dw, $dh );
		$white  = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $dw, $dh, $white );
		imagealphablending( $canvas, true );
		imagecopyresampled( $canvas, $src, 0, 0, 0, 0, $dw, $dh, $w, $h );

		ob_start();
		imagejpeg( $canvas, null, 90 );
		$data = (string) ob_get_clean();

		imagedestroy( $src );
		imagedestroy( $canvas );

		if ( '' === $data ) {
			return null;
		}
		return array(
			'data' => $data,
			'w'    => $dw,
			'h'    => $dh,
		);
	}

	/**
	 * Approximate the rendered width of a string at a font size.
	 *
	 * @param string $s    String.
	 * @param float  $size Font size.
	 * @return float
	 */
	public function width( $s, $size ) {
		$s     = $this->ascii( (string) $s );
		$len   = strlen( $s );
		$units = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( $c >= '0' && $c <= '9' ) {
				$units += 556;
			} elseif ( '.' === $c || ',' === $c || ' ' === $c ) {
				$units += 278;
			} elseif ( '-' === $c ) {
				$units += 333;
			} elseif ( "\x96" === $c ) {
				$units += 500;
			} else {
				$units += 600;
			}
		}
		return $units / 1000 * $size;
	}

	/**
	 * Word-wrap text to a maximum width.
	 *
	 * @param string $text      Text.
	 * @param float  $max_width Column width in points.
	 * @param float  $size      Font size.
	 * @return string[]
	 */
	public function wrap( $text, $max_width, $size ) {
		$text  = $this->ascii( (string) $text );
		$words = preg_split( '/\s+/', trim( $text ) );
		$lines = array();
		$cur   = '';
		foreach ( (array) $words as $word ) {
			if ( '' === $word ) {
				continue;
			}
			while ( $this->width( $word, $size ) > $max_width && strlen( $word ) > 1 ) {
				$cut = strlen( $word );
				while ( $cut > 1 && $this->width( substr( $word, 0, $cut ), $size ) > $max_width ) {
					$cut--;
				}
				if ( '' !== $cur ) {
					$lines[] = $cur;
					$cur     = '';
				}
				$lines[] = substr( $word, 0, $cut );
				$word    = substr( $word, $cut );
			}
			$try = ( '' === $cur ) ? $word : $cur . ' ' . $word;
			if ( '' === $cur || $this->width( $try, $size ) <= $max_width ) {
				$cur = $try;
			} else {
				$lines[] = $cur;
				$cur     = $word;
			}
		}
		if ( '' !== $cur ) {
			$lines[] = $cur;
		}
		return $lines ? $lines : array( '' );
	}

	/**
	 * Reduce a UTF-8 string to the printable ASCII the standard fonts cover.
	 *
	 * @param string $s String.
	 * @return string
	 */
	protected function ascii( $s ) {
		$s = strtr(
			(string) $s,
			array(
				"\xE2\x80\x98" => "'",
				"\xE2\x80\x99" => "'",
				"\xE2\x80\x9C" => '"',
				"\xE2\x80\x9D" => '"',
				"\xE2\x80\x93" => "\x96",
				"\xE2\x80\x94" => '-',
				"\xE2\x80\xA6" => '...',
				"\xC2\xA0"     => ' ',
				"\xC3\x97"     => 'x',
				"\xE2\x80\xA2" => '-',
				"\xE2\x84\xA2" => '(TM)',
				"\xC2\xAE"     => '(R)',
			)
		);
		return (string) preg_replace( '/[^\x20-\x7E\x96]/', '', $s );
	}

	/**
	 * Escape a string for a PDF literal.
	 *
	 * @param string $s String.
	 * @return string
	 */
	protected function esc( $s ) {
		$s = $this->ascii( $s );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
	}

	/**
	 * Assemble and return the finished PDF document as a binary string.
	 *
	 * @return string
	 */
	public function output() {
		if ( $this->open ) {
			$this->pages[] = $this->buf;
			$this->open    = false;
		}
		if ( empty( $this->pages ) ) {
			$this->pages[] = '';
		}
		$n = count( $this->pages );
		$m = count( $this->images );

		$page_ids    = array();
		$content_ids = array();
		$id          = 3;
		for ( $i = 0; $i < $n; $i++ ) {
			$page_ids[ $i ]    = $id++;
			$content_ids[ $i ] = $id++;
		}
		$f1      = $id++;
		$f2      = $id++;
		$img_ids = array();
		for ( $i = 0; $i < $m; $i++ ) {
			$img_ids[ $i ] = $id++;
		}
		$count = $id - 1;

		$objs    = array();
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';

		$kids = array();
		foreach ( $page_ids as $pid ) {
			$kids[] = $pid . ' 0 R';
		}
		$objs[2] = '<< /Type /Pages /Count ' . $n . ' /Kids [' . implode( ' ', $kids ) . '] >>';

		$xobj = '';
		if ( $m ) {
			$refs = array();
			for ( $i = 0; $i < $m; $i++ ) {
				$refs[] = '/Im' . ( $i + 1 ) . ' ' . $img_ids[ $i ] . ' 0 R';
			}
			$xobj = ' /XObject << ' . implode( ' ', $refs ) . ' >>';
		}

		for ( $i = 0; $i < $n; $i++ ) {
			$objs[ $page_ids[ $i ] ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
				. self::PW . ' ' . self::PH . '] /Resources << /Font << /F1 '
				. $f1 . ' 0 R /F2 ' . $f2 . ' 0 R >>' . $xobj . ' >> /Contents '
				. $content_ids[ $i ] . ' 0 R >>';
			$stream                     = $this->pages[ $i ];
			$objs[ $content_ids[ $i ] ] = '<< /Length ' . strlen( $stream )
				. " >>\nstream\n" . $stream . "\nendstream";
		}
		$objs[ $f1 ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[ $f2 ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		for ( $i = 0; $i < $m; $i++ ) {
			$d                      = $this->images[ $i ];
			$objs[ $img_ids[ $i ] ] = '<< /Type /XObject /Subtype /Image /Width ' . $d['w']
				. ' /Height ' . $d['h'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8'
				. ' /Filter /DCTDecode /Length ' . strlen( $d['data'] )
				. " >>\nstream\n" . $d['data'] . "\nendstream";
		}

		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$offsets[ $i ] = strlen( $out );
			$out          .= $i . " 0 obj\n" . $objs[ $i ] . "\nendobj\n";
		}
		$xref = strlen( $out );
		$out .= 'xref' . "\n" . '0 ' . ( $count + 1 ) . "\n";
		$out .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= $count; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$out .= "trailer\n<< /Size " . ( $count + 1 ) . ' /Root 1 0 R >>' . "\n";
		$out .= 'startxref' . "\n" . $xref . "\n" . '%%EOF';
		return $out;
	}
}
