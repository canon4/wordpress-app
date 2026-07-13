<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCFM_AI_API {

    private $provider;
    private $api_key;
    private $model;

    private $endpoints = array(
        'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
        'openai'   => 'https://api.openai.com/v1/chat/completions',
        'mistral'  => 'https://api.mistral.ai/v1/chat/completions',
        'groq'     => 'https://api.groq.com/openai/v1/chat/completions',
    );

    public function __construct() {
        $this->provider = get_option( 'wcfm_ai_provider', 'deepseek' );
        $this->api_key  = get_option( 'wcfm_ai_api_key', '' );
        $this->model    = get_option( 'wcfm_ai_model', 'deepseek-chat' );
    }

    /* ------------------------------------------------------------------ */
    /*  Public                                                              */
    /* ------------------------------------------------------------------ */

    public function generate( array $data ) {
        $prompt = $this->build_prompt( $data );

        switch ( $this->provider ) {
            case 'claude':
                return $this->call_claude( $prompt );
            case 'gemini':
                return $this->call_gemini( $prompt );
            default:
                return $this->call_openai_compatible( $prompt );
        }
    }

    public function test_connection() {
        $sample_data = array(
            'product_name' => 'Tapiz de prueba',
            'category'     => 'Textiles',
            'short_desc'   => 'Tapiz artesanal tejido a mano',
            'materials'    => 'Lana de oveja, tintes naturales',
            'process'      => 'Tejido en telar de madera',
            'benefits'     => 'Decoración única y duradera',
            'vendor_store' => 'Tienda de prueba',
            'vendor_desc'  => 'Artesanos de la región andina',
            'vendor_location'  => 'Colombia',
            'vendor_community' => '',
            'vendor_traditions' => '',
        );

        $result = $this->generate( $sample_data );

        if ( is_wp_error( $result ) ) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
            );
        }

        return array(
            'success' => true,
            'message' => 'Conexión exitosa con ' . ucfirst( $this->provider ),
            'sample'  => isset( $result['descripcion_comercial'] ) ? substr( $result['descripcion_comercial'], 0, 200 ) . '...' : '',
            'tokens'  => isset( $result['tokens_used'] ) ? $result['tokens_used'] : 0,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Prompt builder                                                      */
    /* ------------------------------------------------------------------ */

    private function build_prompt( array $d ) {
        $product_block = "PRODUCTO:\n";
        $product_block .= "- Nombre: " . ( $d['product_name'] ?? '' ) . "\n";
        $product_block .= "- Categoría: " . ( $d['category'] ?? '' ) . "\n";
        $product_block .= "- Descripción breve existente: " . ( $d['short_desc'] ?? 'No proporcionada' ) . "\n";
        $product_block .= "- Materiales: " . ( $d['materials'] ?? 'No especificados' ) . "\n";
        $product_block .= "- Proceso de elaboración: " . ( $d['process'] ?? 'No especificado' ) . "\n";
        $product_block .= "- Beneficios para el comprador: " . ( $d['benefits'] ?? 'No especificados' ) . "\n";

        $vendor_block = "\nVENDEDOR / COMUNIDAD:\n";
        $vendor_block .= "- Nombre de la tienda: " . ( $d['vendor_store'] ?? '' ) . "\n";
        $vendor_block .= "- Descripción de la tienda: " . ( $d['vendor_desc'] ?? '' ) . "\n";
        $vendor_block .= "- Ubicación: " . ( $d['vendor_location'] ?? '' ) . "\n";
        $vendor_block .= "- Historia de la comunidad: " . ( ! empty( $d['vendor_community'] ) ? $d['vendor_community'] : 'No especificada' ) . "\n";
        $vendor_block .= "- Tradiciones: " . ( ! empty( $d['vendor_traditions'] ) ? $d['vendor_traditions'] : 'No especificadas' ) . "\n";

        $instructions = <<<PROMPT
Eres un experto en marketing de artesanías y productos culturales latinoamericanos.
Genera contenido auténtico y emocionalmente resonante para la ficha de producto de un marketplace cultural.

INSTRUCCIONES IMPORTANTES:
- Usa ÚNICAMENTE la información proporcionada. NO inventes historia ni tradiciones.
- Si algún dato no está disponible, omite esa referencia o menciona genéricamente "artesanía tradicional".
- El tono debe ser cálido, cultural, honesto y orientado a valorizar el trabajo artesanal.
- Responde ÚNICAMENTE con el JSON, sin ningún texto adicional, sin bloques de código markdown.

Genera exactamente este objeto JSON con las siguientes claves:
{
  "descripcion_comercial": "Descripción de venta del producto (200-300 palabras). Enfocada en el valor, los materiales y la experiencia de poseer el producto.",
  "historia_origen": "Historia del origen del producto y/o del proceso artesanal (100-150 palabras).",
  "valor_cultural": "Significado cultural y patrimonial del producto (100-150 palabras).",
  "dato_curioso": "Un dato curioso o fascinante sobre el producto, material o técnica (50-80 palabras).",
  "impacto_social": "Cómo la compra de este producto impacta positivamente a la comunidad y artesanos (80-120 palabras).",
  "seo_titulo": "Título SEO optimizado (máximo 60 caracteres).",
  "seo_meta": "Meta descripción SEO (máximo 155 caracteres).",
  "seo_palabras_clave": "5-7 palabras clave separadas por comas."
}
PROMPT;

        return $instructions . "\n\n" . $product_block . $vendor_block;
    }

    /* ------------------------------------------------------------------ */
    /*  Providers                                                           */
    /* ------------------------------------------------------------------ */

    private function call_openai_compatible( $prompt ) {
        $endpoint = $this->endpoints[ $this->provider ] ?? $this->endpoints['deepseek'];

        $body = array(
            'model'           => $this->model,
            'messages'        => array(
                array( 'role' => 'user', 'content' => $prompt ),
            ),
            'temperature'     => 0.75,
            'max_tokens'      => 2000,
            'response_format' => array( 'type' => 'json_object' ),
        );

        $response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ) );

        return $this->parse_openai_response( $response );
    }

    private function call_claude( $prompt ) {
        $body = array(
            'model'      => $this->model ?: 'claude-sonnet-4-6',
            'max_tokens' => 2000,
            'messages'   => array(
                array( 'role' => 'user', 'content' => $prompt ),
            ),
        );

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 ) {
            $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "HTTP $code";
            return new WP_Error( 'api_error', $msg );
        }

        $text = isset( $data['content'][0]['text'] ) ? $data['content'][0]['text'] : '';
        // Clean possible markdown code blocks
        $text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
        $text = preg_replace( '/\s*```$/', '', $text );

        $parsed = json_decode( $text, true );
        if ( ! $parsed ) {
            return new WP_Error( 'parse_error', 'No se pudo parsear la respuesta de Claude.' );
        }

        $parsed['tokens_used'] = isset( $data['usage']['input_tokens'] ) ? $data['usage']['input_tokens'] + $data['usage']['output_tokens'] : 0;
        return $parsed;
    }

    private function call_gemini( $prompt ) {
        $model = $this->model ?: 'gemini-2.0-flash';
        $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->api_key}";

        $body = array(
            'contents' => array(
                array( 'parts' => array( array( 'text' => $prompt ) ) ),
            ),
            'generationConfig' => array(
                'responseMimeType' => 'application/json',
                'temperature'      => 0.75,
                'maxOutputTokens'  => 2000,
            ),
        );

        $response = wp_remote_post( $url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 ) {
            $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "HTTP $code";
            return new WP_Error( 'api_error', $msg );
        }

        $text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed = json_decode( $text, true );
        if ( ! $parsed ) {
            return new WP_Error( 'parse_error', 'No se pudo parsear la respuesta de Gemini.' );
        }

        $in  = $data['usageMetadata']['promptTokenCount'] ?? 0;
        $out = $data['usageMetadata']['candidatesTokenCount'] ?? 0;
        $parsed['tokens_used'] = $in + $out;
        return $parsed;
    }

    /* ------------------------------------------------------------------ */
    /*  Response parser (OpenAI-compatible)                                 */
    /* ------------------------------------------------------------------ */

    private function parse_openai_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 ) {
            $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "HTTP $code";
            return new WP_Error( 'api_error', $msg );
        }

        $content = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
        $parsed  = json_decode( $content, true );

        if ( ! $parsed ) {
            return new WP_Error( 'parse_error', 'No se pudo parsear la respuesta de la IA.' );
        }

        $parsed['tokens_used'] = isset( $data['usage']['total_tokens'] ) ? (int) $data['usage']['total_tokens'] : 0;
        return $parsed;
    }
}
