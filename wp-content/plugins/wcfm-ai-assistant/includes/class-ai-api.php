<?php
/**
 * WCFM AI API handler.
 * Supports: DeepSeek, Claude, OpenAI, Gemini.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCFM_AI_API {

	/** @var string */
	private $provider;

	/** @var string */
	private $api_key;

	/** @var string */
	private $model;

	public function __construct() {
		$this->provider = get_option( 'wcfm_ai_provider', 'deepseek' );
		$this->api_key  = get_option( 'wcfm_ai_api_key', '' );
		$this->model    = get_option( 'wcfm_ai_model',   'deepseek-chat' );
	}

	/**
	 * Generate enriched product content.
	 *
	 * @param array $data Product + vendor data.
	 * @return array|WP_Error
	 */
	public function generate( array $data ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', 'No hay API key configurada. Ve a WooCommerce → IA Assistant.', array( 'status' => 500 ) );
		}

		$prompt = $this->build_prompt( $data );

		switch ( $this->provider ) {
			case 'claude':
				return $this->call_claude( $prompt );
			case 'gemini':
				return $this->call_gemini( $prompt );
			default:
				// deepseek, openai, mistral — all OpenAI-compatible
				return $this->call_openai_compatible( $prompt );
		}
	}

	/**
	 * Build the cultural product prompt.
	 */
	private function build_prompt( array $data ) {
		$name        = sanitize_text_field( $data['product_name']      ?? '' );
		$category    = sanitize_text_field( $data['category']          ?? '' );
		$short_desc  = sanitize_textarea_field( $data['short_desc']    ?? '' );
		$materials   = sanitize_textarea_field( $data['materials']     ?? '' );
		$process     = sanitize_textarea_field( $data['process']       ?? '' );
		$benefits    = sanitize_textarea_field( $data['benefits']      ?? '' );
		$v_store     = sanitize_text_field( $data['vendor_store']      ?? '' );
		$v_desc      = sanitize_textarea_field( $data['vendor_desc']   ?? '' );
		$v_location  = sanitize_text_field( $data['vendor_location']   ?? '' );
		$v_community = sanitize_textarea_field( $data['vendor_community']  ?? '' );
		$v_tradition = sanitize_textarea_field( $data['vendor_traditions'] ?? '' );

		return "Eres un copywriter especializado en productos artesanales y culturales de comunidades indígenas y artesanales de Latinoamérica.
Escribes en español, con tono cálido, auténtico y respetuoso que honra el patrimonio cultural de las comunidades.

REGLA CRÍTICA: Usa ÚNICAMENTE la información proporcionada. No inventes historia, fechas, tradiciones ni datos ausentes en el texto. Si un campo está vacío, omite ese aspecto.

INFORMACIÓN DEL PRODUCTO:
- Nombre: {$name}
- Categoría: {$category}
- Descripción base: {$short_desc}
- Materiales: {$materials}
- Proceso de fabricación: {$process}
- Beneficios y usos: {$benefits}

INFORMACIÓN DEL VENDEDOR / COMUNIDAD:
- Nombre de la tienda: {$v_store}
- Historia / descripción: {$v_desc}
- Ubicación geográfica: {$v_location}
- Historia de la comunidad: {$v_community}
- Tradiciones asociadas: {$v_tradition}

Genera contenido enriquecido y devuelve ÚNICAMENTE un objeto JSON válido (sin bloques de código, sin markdown, sin texto adicional) con esta estructura exacta:
{
  \"descripcion_comercial\": \"texto de 200 a 300 palabras, orientado a la venta, emotivo y auténtico\",
  \"historia_origen\": \"texto de 120 a 200 palabras sobre la historia de la comunidad o tradición relacionada con el producto\",
  \"valor_cultural\": \"texto de 80 a 150 palabras sobre el contexto cultural y significado del producto\",
  \"dato_curioso\": \"texto de 50 a 80 palabras que empieza con la frase '¿Sabías que'\",
  \"impacto_social\": \"texto de 60 a 100 palabras sobre cómo la compra beneficia a la comunidad\",
  \"seo_titulo\": \"título SEO de máximo 60 caracteres que incluye la palabra clave principal\",
  \"seo_meta\": \"meta descripción de máximo 155 caracteres, descriptiva e invita a la acción\",
  \"seo_palabras_clave\": [\"palabra1\", \"palabra2\", \"palabra3\", \"palabra4\", \"palabra5\", \"palabra6\"]
}";
	}

	/**
	 * Call OpenAI-compatible API (DeepSeek, OpenAI, Mistral, Groq).
	 */
	private function call_openai_compatible( $prompt ) {
		$endpoints = array(
			'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
			'openai'   => 'https://api.openai.com/v1/chat/completions',
			'mistral'  => 'https://api.mistral.ai/v1/chat/completions',
			'groq'     => 'https://api.groq.com/openai/v1/chat/completions',
		);

		$url = $endpoints[ $this->provider ] ?? $endpoints['deepseek'];

		$body = array(
			'model'       => $this->model,
			'messages'    => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
			'temperature'     => 0.75,
			'max_tokens'      => 2000,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = wp_remote_post( $url, array(
			'timeout' => 90,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );

		return $this->parse_openai_response( $response );
	}

	/**
	 * Call Anthropic Claude API.
	 */
	private function call_claude( $prompt ) {
		$model = $this->model ?: 'claude-sonnet-4-6';

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 90,
			'headers' => array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => 2000,
				'messages'   => array(
					array( 'role' => 'user', 'content' => $prompt ),
				),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http_code !== 200 ) {
			$msg = $body['error']['message'] ?? "Error HTTP {$http_code} de Claude";
			return new WP_Error( 'claude_error', $msg, array( 'status' => 500 ) );
		}

		$content = $body['content'][0]['text'] ?? '';

		// Claude may sometimes wrap JSON in markdown — strip it
		$content = preg_replace( '/^```json\s*/i', '', $content );
		$content = preg_replace( '/\s*```$/', '', $content );

		$parsed = json_decode( $content, true );

		if ( ! $parsed || ! is_array( $parsed ) ) {
			return new WP_Error( 'parse_error', 'No se pudo interpretar la respuesta de Claude. Inténtalo de nuevo.', array( 'status' => 500 ) );
		}

		$parsed['tokens_used'] = ( $body['usage']['input_tokens'] ?? 0 ) + ( $body['usage']['output_tokens'] ?? 0 );
		return $parsed;
	}

	/**
	 * Call Google Gemini API.
	 */
	private function call_gemini( $prompt ) {
		$model    = $this->model ?: 'gemini-2.0-flash';
		$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $this->api_key;

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 90,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'contents' => array(
					array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ),
				),
				'generationConfig' => array(
					'temperature'      => 0.75,
					'maxOutputTokens'  => 2000,
					'responseMimeType' => 'application/json',
				),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http_code !== 200 ) {
			$msg = $body['error']['message'] ?? "Error HTTP {$http_code} de Gemini";
			return new WP_Error( 'gemini_error', $msg, array( 'status' => 500 ) );
		}

		$content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		$parsed  = json_decode( $content, true );

		if ( ! $parsed || ! is_array( $parsed ) ) {
			return new WP_Error( 'parse_error', 'No se pudo interpretar la respuesta de Gemini.', array( 'status' => 500 ) );
		}

		$total_tokens = ( $body['usageMetadata']['promptTokenCount'] ?? 0 )
		              + ( $body['usageMetadata']['candidatesTokenCount'] ?? 0 );
		$parsed['tokens_used'] = $total_tokens;

		return $parsed;
	}

	/**
	 * Parse a standard OpenAI-format response.
	 */
	private function parse_openai_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_error', 'Error de conexión: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http_code !== 200 ) {
			$msg = $body['error']['message'] ?? "Error HTTP {$http_code}";
			return new WP_Error( 'api_error', $msg, array( 'status' => $http_code ) );
		}

		$content = $body['choices'][0]['message']['content'] ?? '';
		$parsed  = json_decode( $content, true );

		if ( ! $parsed || ! is_array( $parsed ) ) {
			return new WP_Error( 'parse_error', 'Respuesta de IA inválida. Inténtalo de nuevo.', array( 'status' => 500 ) );
		}

		$parsed['tokens_used'] = $body['usage']['total_tokens'] ?? 0;
		return $parsed;
	}

	/**
	 * Quick connection test with a minimal prompt.
	 *
	 * @return array
	 */
	public function test_connection() {
		if ( empty( $this->api_key ) ) {
			return array( 'success' => false, 'message' => 'No hay API key configurada.' );
		}

		$test_data = array(
			'product_name'       => 'Tapiz artesanal de prueba',
			'category'           => 'Artesanía textil',
			'short_desc'         => '',
			'materials'          => 'Lana de oveja natural',
			'process'            => 'Tejido a telar de cintura',
			'benefits'           => 'Decoración del hogar',
			'vendor_store'       => 'Tienda de prueba',
			'vendor_desc'        => 'Comunidad artesanal andina',
			'vendor_location'    => 'Oaxaca, México',
			'vendor_community'   => '',
			'vendor_traditions'  => '',
		);

		$result = $this->generate( $test_data );

		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'message' => $result->get_error_message() );
		}

		return array(
			'success' => true,
			'message' => 'Conexión exitosa con ' . ucfirst( $this->provider ),
			'sample'  => mb_substr( $result['descripcion_comercial'] ?? '', 0, 120 ) . '…',
			'tokens'  => $result['tokens_used'] ?? 0,
		);
	}
}
