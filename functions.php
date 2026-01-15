<?php
/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		array(),
		HELLO_ELEMENTOR_CHILD_VERSION
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 999 );

/**
 * =====================================================
 * NEON DATABASE CONNECTION
 * =====================================================
 */

// Neon Database Configuration (HTTP API)
define('NEON_DB_HOST', 'ep-flat-block-ahfcd811-pooler.c-3.us-east-1.aws.neon.tech');
define('NEON_DB_NAME', 'neondb');
define('NEON_DB_USER', 'neondb_owner');
define('NEON_DB_PASSWORD', 'npg_Y4zUaNi0LbXd');

/**
 * Execute SQL query via Neon HTTP API (Serverless Driver)
 * @param string $sql SQL query to execute
 * @param array $params Parameters for the query (optional)
 * @return array|false Results array or false on error
 */
function neon_query($sql, $params = []) {
    // Use the non-pooler host for HTTP API
    $http_host = str_replace('-pooler', '', NEON_DB_HOST);
    $url = 'https://' . $http_host . '/sql';

    // Build the request body for Neon serverless driver
    $body = [
        'query' => $sql,
        'params' => array_values($params)
    ];

    // Password needs to be URL encoded in connection string
    $encoded_password = urlencode(NEON_DB_PASSWORD);
    $connection_string = 'postgresql://' . NEON_DB_USER . ':' . $encoded_password . '@' . $http_host . '/' . NEON_DB_NAME . '?sslmode=require';

    $args = [
        'method' => 'POST',
        'headers' => [
            'Content-Type' => 'application/json',
            'Neon-Connection-String' => $connection_string
        ],
        'body' => json_encode($body),
        'timeout' => 30
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        error_log('Neon HTTP Error: ' . $response->get_error_message());
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if ($status_code !== 200) {
        error_log('Neon API Error (Status ' . $status_code . '): ' . $response_body);
        return false;
    }

    return $data;
}

/**
 * Initialize Neon database tables (run once)
 */
function init_neon_tables() {
    // Create agencies table
    $result1 = neon_query("
        CREATE TABLE IF NOT EXISTS agencies (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255),
            contact_name VARCHAR(255),
            email VARCHAR(255) UNIQUE NOT NULL,
            phone VARCHAR(50),
            country VARCHAR(100),
            company VARCHAR(255),
            message TEXT,
            source VARCHAR(50) DEFAULT 'contact_form',
            status VARCHAR(50) DEFAULT 'lead',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    if ($result1 === false) {
        error_log('Neon DB Init Error: Failed to create agencies table');
        return false;
    }

    // Create enrollments table
    $result2 = neon_query("
        CREATE TABLE IF NOT EXISTS enrollments (
            id SERIAL PRIMARY KEY,
            agency_id INTEGER REFERENCES agencies(id),
            student_first_name VARCHAR(255),
            student_last_name VARCHAR(255),
            student_email VARCHAR(255),
            student_phone VARCHAR(50),
            student_phone_prefix VARCHAR(10),
            student_dob DATE,
            student_nationality VARCHAR(100),
            student_gender VARCHAR(20),
            student_address TEXT,
            course_category VARCHAR(100),
            course_type VARCHAR(100),
            course_weeks INTEGER,
            start_date DATE,
            housing_required BOOLEAN DEFAULT FALSE,
            accommodation_type VARCHAR(100),
            housing_type VARCHAR(100),
            check_in_date DATE,
            check_out_date DATE,
            transfer_required BOOLEAN DEFAULT FALSE,
            transfer_type VARCHAR(50),
            insurance_required BOOLEAN DEFAULT FALSE,
            insurance_type VARCHAR(50),
            insurance_start_date DATE,
            insurance_end_date DATE,
            total_price DECIMAL(10,2),
            status VARCHAR(50) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    if ($result2 === false) {
        error_log('Neon DB Init Error: Failed to create enrollments table');
        return false;
    }

    return true;
}

/**
 * Save agency to Neon database
 */
function save_agency_to_neon($data) {
    // Check if agency exists by email
    $result = neon_query("SELECT id FROM agencies WHERE email = $1", [$data['email']]);

    if ($result === false) {
        error_log('Neon DB Save Agency Error: Query failed');
        return false;
    }

    // Check if we got rows back
    $rows = $result['rows'] ?? [];

    if (!empty($rows)) {
        // Update existing agency
        $update_result = neon_query("
            UPDATE agencies SET
                name = COALESCE($1, name),
                contact_name = COALESCE($2, contact_name),
                phone = COALESCE($3, phone),
                country = COALESCE($4, country),
                company = COALESCE($5, company),
                updated_at = CURRENT_TIMESTAMP
            WHERE email = $6
            RETURNING id
        ", [
            $data['name'] ?? null,
            $data['contact_name'] ?? null,
            $data['phone'] ?? null,
            $data['country'] ?? null,
            $data['company'] ?? null,
            $data['email']
        ]);

        if ($update_result && !empty($update_result['rows'])) {
            return $update_result['rows'][0]['id'];
        }
        return $rows[0]['id'];
    } else {
        // Insert new agency
        $insert_result = neon_query("
            INSERT INTO agencies (name, contact_name, email, phone, country, company, message, source)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
            RETURNING id
        ", [
            $data['name'] ?? null,
            $data['contact_name'] ?? null,
            $data['email'],
            $data['phone'] ?? null,
            $data['country'] ?? null,
            $data['company'] ?? null,
            $data['message'] ?? null,
            $data['source'] ?? 'contact_form'
        ]);

        if ($insert_result && !empty($insert_result['rows'])) {
            return $insert_result['rows'][0]['id'];
        }
        return false;
    }
}

/**
 * Save enrollment to Neon database
 */
function save_enrollment_to_neon($data) {
    // First, get or create agency
    $agency_id = null;
    if (!empty($data['agency_email'])) {
        $agency_id = save_agency_to_neon([
            'name' => $data['agency_name'] ?? null,
            'contact_name' => $data['agency_contact'] ?? null,
            'email' => $data['agency_email'],
            'source' => 'enrollment'
        ]);
    }

    // Insert enrollment
    $result = neon_query("
        INSERT INTO enrollments (
            agency_id, student_first_name, student_last_name, student_email,
            student_phone, student_phone_prefix, student_dob, student_nationality,
            student_gender, student_address, course_category, course_type,
            course_weeks, start_date, housing_required, accommodation_type,
            housing_type, check_in_date, check_out_date, transfer_required,
            transfer_type, insurance_required, insurance_type,
            insurance_start_date, insurance_end_date, total_price
        ) VALUES (
            $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22, $23, $24, $25, $26
        )
        RETURNING id
    ", [
        $agency_id,
        $data['student_first_name'] ?? null,
        $data['student_last_name'] ?? null,
        $data['student_email'] ?? null,
        $data['student_phone'] ?? null,
        $data['student_phone_prefix'] ?? null,
        !empty($data['student_dob']) ? $data['student_dob'] : null,
        $data['student_nationality'] ?? null,
        $data['student_gender'] ?? null,
        $data['student_address'] ?? null,
        $data['course_category'] ?? null,
        $data['course_type'] ?? null,
        !empty($data['course_weeks']) ? (int)$data['course_weeks'] : null,
        !empty($data['start_date']) ? $data['start_date'] : null,
        $data['housing_required'] ?? false,
        $data['accommodation_type'] ?? null,
        $data['housing_type'] ?? null,
        !empty($data['check_in_date']) ? $data['check_in_date'] : null,
        !empty($data['check_out_date']) ? $data['check_out_date'] : null,
        $data['transfer_required'] ?? false,
        $data['transfer_type'] ?? null,
        $data['insurance_required'] ?? false,
        $data['insurance_type'] ?? null,
        !empty($data['insurance_start_date']) ? $data['insurance_start_date'] : null,
        !empty($data['insurance_end_date']) ? $data['insurance_end_date'] : null,
        !empty($data['total_price']) ? (float)$data['total_price'] : null
    ]);

    if ($result && !empty($result['rows'])) {
        return $result['rows'][0]['id'];
    }

    error_log('Neon DB Save Enrollment Error: Insert failed');
    return false;
}

// Initialize tables on theme activation (or first load)
add_action('after_setup_theme', function() {
    if (get_option('neon_tables_initialized') !== 'yes') {
        if (init_neon_tables()) {
            update_option('neon_tables_initialized', 'yes');
        }
    }
});

/**
 * =====================================================
 * END NEON DATABASE CONNECTION
 * =====================================================
 */

function obtener_token_zoho() {
    $client_id = '1000.HRH2TKGZ1GUXW2PBK34EX5FQLGSMVG';
    $client_secret = '9987290137a0f74a1ff775c9df2a92a171a90ce37b';
    $redirect_uri = 'https://www.hablandis.com/test/';
    $authorization_code = '1000.5d21a40a18d24ba53a80c4bcf4f38894.e851532302d1ed71723c7cd6f8b99ce8';

    $url = 'https://accounts.zoho.eu/oauth/v2/token';

    $args = array(
        'body' => array(
            'grant_type' => 'authorization_code',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect_uri,
            'code' => $authorization_code
        ),
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded'
        )
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        echo 'Error al conectarse a Zoho: ' . $response->get_error_message();
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['access_token'])) {
        echo '<strong>Access Token:</strong> ' . esc_html($data['access_token']) . '<br>';
        echo '<strong>Refresh Token:</strong> ' . esc_html($data['refresh_token']) . '<br>';
        echo '<strong>Expira en:</strong> ' . esc_html($data['expires_in']) . ' segundos<br>';
    } else {
        echo 'Error al obtener token: ';
        print_r($data);
    }
}

// Puedes llamar a esta función desde una página temporal, admin, o hook:
add_shortcode('zoho_obtener_token', 'obtener_token_zoho');

/**
 * Partner Portal - Shortcode estilo SpeakPolish
 * Uso: [hablandis_partner_portal]
 */
function hablandis_partner_portal_shortcode() {
    ob_start();
    ?>
    <style>
        /* Partner Portal Styles - Paleta Oficial Hablandis */
        html {
            scroll-behavior: smooth;
            scroll-padding-top: 100px;
        }

        .hp-portal {
            /* Paleta de colores oficial */
            --primary-violet: #c4d4a4;      /* Verde claro - color de recuadros */
            --primary-dark: #12055f;         /* Azul oscuro */
            --accent-yellow: #ffc846;        /* Amarillo */
            --accent-red: #e30a18;           /* Rojo */
            --light-green: #c4d4a4;          /* Verde claro */
            --dark-green: #007567;           /* Verde oscuro */
            --cream: #e30a18;                /* Para gradientes */
            --white: #ffffff;
            --black: #000000;
            /* Tipografías */
            --font-titles: 'Space Mono', monospace;
            --font-body: 'Raleway', sans-serif;
            font-family: var(--font-body);
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg,
                #c4d4a4 0%,
                #d4e4b4 25%,
                #e4f4c4 50%,
                #f4f9e4 75%,
                rgba(255, 255, 255, 0.95) 100%);
            min-height: 100vh;
            overflow-x: hidden;
            width: 100%;
        }

        .hp-portal * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        /* Main Container */
        .hp-main {
            max-width: 1600px;
            margin: 0 auto;
            padding: 5px 15px;
            overflow-x: hidden;
            width: 100%;
        }

        /* Header Card */
        .hp-header {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            margin-bottom: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .hp-logo {
            display: flex;
            align-items: flex-start;
            gap: 5px;
        }

        .hp-logo-img {
            height: 35px;
            width: auto;
            /* Logo en azul oscuro original */
        }

        .hp-logo span {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 700;
            margin-top: -5px;
            font-family: var(--font-titles);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .hp-nav {
            display: flex;
            gap: 35px;
        }

        .hp-nav a {
            text-decoration: none;
            color: var(--primary-dark);
            font-weight: 500;
            font-size: 16px;
            transition: font-weight 0.2s ease;
            font-family: var(--font-titles);
        }

        .hp-nav a:hover {
            font-weight: 700;
        }

        .hp-header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .hp-header-cta {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-family: var(--font-titles);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .hp-header-cta:hover {
            background: var(--white);
            color: var(--primary-dark);
        }

        /* Hero Section Card - Estilo Geométrico */
        .hp-hero {
            background: transparent;
            border-radius: 15px;
            padding: 80px 60px;
            margin-bottom: 5px;
            position: relative;
            overflow: visible;
            min-height: 600px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 80px;
            align-items: center;
        }

        .hp-hero-content {
            display: flex;
            flex-direction: column;
            gap: 30px;
            justify-content: center;
        }

        .hp-hero-right {
            display: flex;
            flex-direction: column;
            gap: 25px;
            align-items: center;
            justify-content: center;
        }

        .hp-hero-subtitle {
            color: var(--primary-dark);
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 5px;
            text-transform: uppercase;
            font-family: var(--font-titles);
            margin: 0 0 -10px 0;
        }

        .hp-hero-description {
            max-width: 420px;
            color: var(--primary-dark);
            font-size: 17px;
            line-height: 1.4;
            text-align: left;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-family: var(--font-titles);
            margin: 0 0 10px 0;
            padding-left: 29.2px;
        }

        /* Título PARTNER - Imagen */
        .hp-hero-title-container {
            text-align: left;
            padding: 0;
            margin: 0;
            overflow: visible;
            width: 100%;
        }

        .hp-hero-partner-img {
            width: 120%;
            height: auto;
            max-height: none;
            max-width: 900px;
            display: block;
            margin: 0;
            padding: 0;
            object-fit: contain;
            object-position: left center;
            filter: drop-shadow(1px 1px 0px rgba(107, 91, 123, 0.6))
                    drop-shadow(-1px -1px 0px rgba(107, 91, 123, 0.6))
                    drop-shadow(1px -1px 0px rgba(107, 91, 123, 0.6))
                    drop-shadow(-1px 1px 0px rgba(107, 91, 123, 0.6))
                    drop-shadow(0 4px 12px rgba(0, 0, 0, 0.2));
        }

        /* Mosaico de fotos - 2 arriba + 1 abajo */
        .hp-hero-mosaic {
            display: grid;
            grid-template-columns: repeat(2, 220px);
            grid-template-rows: 180px 120px;
            gap: 14px;
            width: auto;
            margin-left: 1cm;
        }

        .hp-mosaic-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }

        .hp-mosaic-img:nth-child(3) {
            grid-column: span 2;
        }

        /* CTA en hero */
        .hp-hero-cta-bottom {
            margin-top: 20px;
        }

        .hp-hero-cta-top {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-family: var(--font-titles);
        }

        .hp-hero-cta-top:hover {
            background: var(--white);
            transform: translateY(-2px);
        }

        /* What We've Built Section */
        .hp-what-built {
            background: transparent;
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 5px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hp-what-built h2 {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            font-family: var(--font-titles);
            text-align: center;
            margin-bottom: 30px;
        }

        .hp-infografia-container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            text-align: center;
        }

        .hp-infografia-img {
            width: 100%;
        }

        /* Contact CTA Sections */
        .hp-contact-cta {
            background: linear-gradient(135deg, rgba(175, 160, 200, 0.9) 0%, rgba(213, 204, 229, 0.3) 100%);
            border-radius: 15px;
            padding: 60px 40px;
            margin: 60px 0;
            text-align: center;
            box-shadow: 0 10px 30px rgba(175, 160, 200, 0.3);
        }

        .hp-cta-content h2 {
            font-size: 36px;
            font-weight: 700;
            color: var(--primary-dark);
            font-family: var(--font-titles);
            margin-bottom: 15px;
            text-align: center;
        }

        .hp-cta-content p {
            font-size: 18px;
            color: var(--primary-dark);
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .hp-cta-button {
            display: inline-block;
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 16px 40px;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(251, 186, 58, 0.3);
        }

        .hp-cta-button:hover {
            background: white;
            color: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(251, 186, 58, 0.4);
        }

        @media (max-width: 768px) {
            .hp-contact-cta {
                padding: 40px 20px;
                margin: 40px 0;
            }

            .hp-cta-content h2 {
                font-size: 28px;
            }

            .hp-cta-content p {
                font-size: 16px;
            }

            .hp-cta-button {
                padding: 14px 30px;
                font-size: 16px;
            }
        }

        /* Advantages Section Card */
        .hp-advantages {
            background: transparent;
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 5px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hp-advantages-header {
            text-align: left;
            margin-bottom: 20px;
        }

        .hp-advantages h2 {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            font-family: var(--font-titles);
            text-align: center;
        }

        .hp-advantages-desc {
            max-width: 100%;
            width: 100%;
            color: var(--primary-dark);
            font-size: 18px;
            line-height: 1.6;
            text-align: left;
            margin-bottom: 40px;
            font-weight: 400;
        }

        /* Accordion - Stacked Cards */
        .hp-accordion {
            display: flex;
            flex-direction: column;
            gap: 0;
            position: relative;
        }

        .hp-accordion-item {
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
            margin-top: -15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }

        .hp-accordion-item:first-child {
            margin-top: 0;
        }

        .hp-accordion-item:nth-child(1) { z-index: 5; }
        .hp-accordion-item:nth-child(2) { z-index: 4; }
        .hp-accordion-item:nth-child(3) { z-index: 3; }
        .hp-accordion-item:nth-child(4) { z-index: 2; }
        .hp-accordion-item:nth-child(5) { z-index: 1; }

        /* Colores acordeón - Paleta oficial */
        .hp-accordion-item:nth-child(1) { background: #E8BFB7; }  /* Rosa pastel */
        .hp-accordion-item:nth-child(2) { background: #B7C8E8; }  /* Azul lavanda */
        .hp-accordion-item:nth-child(3) { background: #849364; }  /* Verde oliva */
        .hp-accordion-item:nth-child(4) { background: #693D35; }  /* Marrón oscuro */
        .hp-accordion-item:nth-child(5) { background: #F4D8A0; }  /* Amarillo beige */
        .hp-accordion-item:nth-child(3) .hp-accordion-number,
        .hp-accordion-item:nth-child(3) .hp-accordion-title,
        .hp-accordion-item:nth-child(3) .hp-accordion-icon,
        .hp-accordion-item:nth-child(3) .hp-accordion-body h4,
        .hp-accordion-item:nth-child(3) .hp-accordion-body p,
        .hp-accordion-item:nth-child(4) .hp-accordion-number,
        .hp-accordion-item:nth-child(4) .hp-accordion-title,
        .hp-accordion-item:nth-child(4) .hp-accordion-icon,
        .hp-accordion-item:nth-child(4) .hp-accordion-body h4,
        .hp-accordion-item:nth-child(4) .hp-accordion-body p { color: var(--white); }

        .hp-accordion-header {
            display: flex;
            align-items: center;
            padding: 25px 30px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .hp-accordion-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-right: 40px;
            min-width: 50px;
            font-family: var(--font-titles);
        }

        .hp-accordion-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--primary-dark);
            flex: 1;
            font-family: var(--font-titles);
        }

        .hp-accordion-icon {
            font-size: 24px;
            color: var(--primary-dark);
            transition: transform 0.3s ease;
        }

        .hp-accordion-item.active .hp-accordion-icon {
            transform: rotate(45deg);
        }

        .hp-accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease;
        }

        .hp-accordion-item.active .hp-accordion-content {
            max-height: 300px;
        }

        .hp-accordion-body {
            padding: 0 30px 30px 120px;
        }

        .hp-accordion-body h4 {
            font-size: 22px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-family: var(--font-titles);
        }

        .hp-accordion-body p {
            font-size: 15px;
            color: var(--primary-dark);
            line-height: 1.7;
            opacity: 0.85;
        }

        /* Programs Section Card */
        .hp-programs {
            background: transparent;
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 5px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hp-programs h2 {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            text-align: center;
            margin-bottom: 50px;
            font-family: var(--font-titles);
        }

        .hp-programs-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
        }

        .hp-program-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .hp-program-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .hp-program-card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
        }

        .hp-program-card-content {
            padding: 25px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            align-items: center;
            text-align: center;
        }

        .hp-program-card h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 10px;
            font-family: var(--font-titles);
        }

        .hp-program-card p {
            font-size: 14px;
            color: var(--primary-dark);
            opacity: 0.7;
            margin-bottom: 20px;
            flex-grow: 1;
        }

        .hp-program-cta {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 10px 22px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-family: var(--font-titles);
            text-transform: uppercase;
        }

        .hp-program-cta:hover {
            background: var(--white);
            color: var(--primary-dark);
        }

        /* Programs Grid - Tablet Large (iPad Air 13" landscape ~1194px) */
        @media (max-width: 1200px) and (min-width: 1025px) {
            .hp-programs-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            .hp-program-card img {
                height: 200px;
                object-position: center 20%;
            }
        }

        /* Programs Grid - Tablet (iPad portrait and smaller tablets) */
        @media (max-width: 1024px) and (min-width: 769px) {
            .hp-programs-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            .hp-program-card img {
                height: 180px;
                object-position: center 20%;
            }
        }

        /* Programs Grid - Mobile Landscape */
        @media (max-width: 768px) and (orientation: landscape) {
            .hp-programs-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            .hp-program-card img {
                height: 150px;
                object-position: center 20%;
            }
            .hp-program-card-content {
                padding: 15px;
            }
            .hp-program-card h3 {
                font-size: 16px !important;
            }
            .hp-program-card p {
                font-size: 12px !important;
                margin-bottom: 12px;
            }
        }

        /* Programs Grid - Mobile Portrait */
        @media (max-width: 768px) and (orientation: portrait) {
            .hp-program-card img {
                height: 200px;
                object-position: center 20%;
            }
        }

        /* Our Team Section */
        .hp-team {
            background: transparent;
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 5px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hp-team h2 {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            text-align: center;
            margin-bottom: 50px;
            font-family: var(--font-titles);
        }

        .hp-team-grid {
            display: grid;
            grid-template-columns: repeat(3, 320px);
            gap: 30px;
            max-width: 1100px;
            margin: 0 auto;
            justify-content: center;
        }

        .hp-team-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            display: flex;
            flex-direction: column;
            width: 320px;
            height: 480px;
        }

        .hp-team-card:nth-child(4) {
            grid-column: 1 / 2;
            grid-row: 2;
            justify-self: end;
            margin-right: -170px;
        }

        .hp-team-card:nth-child(5) {
            grid-column: 3 / 4;
            grid-row: 2;
            justify-self: start;
            margin-left: -170px;
        }

        .hp-team-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .hp-team-card-img {
            width: 100%;
            height: 320px;
            flex-shrink: 0;
            object-fit: cover;
            object-position: center 20%;
        }

        .hp-team-card-content {
            padding: 18px 15px;
            text-align: center;
            background: var(--white);
            width: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            flex: 1;
        }

        .hp-team-card h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 6px;
            font-family: var(--font-titles);
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hp-team-card .hp-team-role {
            font-size: 11px;
            color: var(--primary-dark);
            font-weight: 400;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.7;
            margin-bottom: 12px;
            line-height: 1.4;
            min-height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hp-team-card-btn {
            display: inline-block;
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            margin-top: 8px;
        }

        .hp-team-card-btn:hover {
            background: var(--primary-dark);
            color: var(--accent-yellow);
            transform: scale(1.05);
        }

        /* Team Cards Responsive - Tablet */
        @media (max-width: 1024px) and (min-width: 769px) {
            .hp-team-grid {
                grid-template-columns: repeat(3, 280px);
                gap: 25px;
                max-width: 100%;
                padding: 0 20px;
                justify-content: center;
            }

            .hp-team-card {
                width: 280px;
                height: 420px;
            }

            .hp-team-card:nth-child(4) {
                grid-column: 1 / 2;
                grid-row: 2;
                justify-self: end;
                margin-right: -140px;
                width: 280px;
                height: 420px;
            }

            .hp-team-card:nth-child(5) {
                grid-column: 3 / 4;
                grid-row: 2;
                justify-self: start;
                margin-left: -140px;
                width: 280px;
                height: 420px;
            }

            .hp-team-card-img {
                height: 280px;
            }

            .hp-team-card h3 {
                font-size: 16px;
            }

            .hp-team-card .hp-team-role {
                font-size: 10px;
            }

            .hp-team-card-btn {
                font-size: 11px;
                padding: 8px 16px;
            }
        }

        /* Team Cards Responsive - Mobile */
        @media (max-width: 768px) {
            .hp-team-grid {
                grid-template-columns: 1fr;
                gap: 20px;
                padding: 0 15px;
                max-width: 100%;
                justify-items: center;
            }

            .hp-team-card {
                width: 100%;
                max-width: 320px;
                height: 460px;
                border-radius: 16px;
                grid-column: auto !important;
                grid-row: auto !important;
                justify-self: center !important;
            }

            .hp-team-card:nth-child(4),
            .hp-team-card:nth-child(5) {
                margin-left: 0 !important;
                margin-right: 0 !important;
                width: 100%;
                max-width: 320px;
                height: 460px;
                grid-column: auto !important;
                grid-row: auto !important;
                justify-self: center !important;
            }

            .hp-team-card-img {
                height: 320px;
            }

            .hp-team-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 15px 30px rgba(0,0,0,0.25);
            }

            .hp-team-card-content {
                padding: 15px;
            }

            .hp-team-card h3 {
                font-size: 18px;
                margin-bottom: 6px;
            }

            .hp-team-card .hp-team-role {
                font-size: 11px;
                letter-spacing: 0.3px;
                margin-bottom: 12px;
            }

            .hp-team-card-btn {
                padding: 8px 16px;
                font-size: 12px;
                margin-top: 8px;
            }
        }

        /* Team Member Modal */
        .hp-team-modal .hp-modal-content {
            max-width: 900px;
            overflow-y: auto;
        }

        .hp-team-modal-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #5a4a6a 100%);
            color: white;
            padding: 30px;
            display: flex;
            gap: 25px;
            align-items: center;
            flex-shrink: 0;
        }

        .hp-team-modal-img {
            width: 160px;
            height: 160px;
            border-radius: 50%;
            object-fit: cover;
            object-position: center top;
            flex-shrink: 0;
            border: 4px solid var(--accent-yellow);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }

        .hp-team-modal-info {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .hp-team-modal-info h2 {
            font-size: 32px;
            color: white;
            margin-bottom: 6px;
            font-family: var(--font-titles);
        }

        .hp-team-modal-info .hp-team-role {
            font-size: 14px;
            color: white;
            font-weight: 400;
            margin-bottom: 18px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
        }

        .hp-team-contact {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 12px;
            align-items: flex-start;
        }

        .hp-team-contact-item {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: white;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.15);
            padding: 8px 14px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .hp-team-contact-item:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        .hp-team-contact-item i {
            color: var(--accent-yellow);
            flex-shrink: 0;
        }

        .hp-team-contact-item a {
            color: white;
            text-decoration: none;
            transition: color 0.3s ease;
            white-space: nowrap;
        }

        .hp-team-contact-item a:hover {
            color: var(--accent-yellow);
        }

        .hp-team-header-cta {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            font-family: var(--font-titles);
            text-transform: uppercase;
            margin-top: 0;
        }

        .hp-team-header-cta:hover {
            background: white;
            color: var(--primary-dark);
        }

        .hp-team-modal-body {
            padding: 40px;
            background: white;
            overflow-y: auto;
        }

        .hp-team-modal-intro {
            margin-bottom: 30px;
        }

        .hp-team-modal-intro p {
            font-size: 16px;
            color: var(--primary-dark);
            line-height: 1.8;
            margin: 0;
            font-style: normal;
            font-weight: 700;
        }

        .hp-team-modal-bio h3 {
            font-size: 24px;
            color: var(--primary-dark);
            margin-bottom: 20px;
            font-family: var(--font-titles);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .hp-team-modal-bio h3::after {
            content: '';
            flex-grow: 1;
            height: 3px;
            background: var(--accent-yellow);
            border-radius: 2px;
        }

        .hp-team-modal-bio p {
            font-size: 15px;
            color: var(--primary-dark);
            line-height: 1.8;
            margin-bottom: 18px;
            opacity: 0.9;
        }

        .hp-team-modal-bio p strong {
            color: var(--primary-dark);
            font-weight: 700;
        }

        .hp-team-modal-bio ul {
            list-style: none;
            padding: 0;
            margin: 25px 0;
            background: #f9f9f9;
            padding: 25px;
            border-radius: 15px;
        }

        .hp-team-modal-bio ul li {
            font-size: 15px;
            color: var(--primary-dark);
            line-height: 1.8;
            padding-left: 30px;
            position: relative;
            margin-bottom: 12px;
        }

        .hp-team-modal-bio ul li::before {
            content: '✓';
            position: absolute;
            left: 0;
            color: var(--accent-yellow);
            font-weight: bold;
            font-size: 18px;
            width: 22px;
            height: 22px;
            background: rgba(255, 200, 70, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hp-team-modal-cta-wrapper {
            text-align: center;
            padding-top: 30px;
            border-top: 2px solid #f0f0f0;
            margin-top: 30px;
        }

        .hp-team-modal-cta {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 18px 40px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            font-family: var(--font-titles);
            text-transform: uppercase;
            box-shadow: 0 5px 20px rgba(255, 200, 70, 0.4);
        }

        .hp-team-modal-cta:hover {
            background: var(--primary-dark);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(18, 5, 95, 0.4);
        }

        .hp-team-modal .hp-modal-close {
            top: 15px;
            right: 15px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .hp-team-modal .hp-modal-close:hover {
            background: white;
            color: var(--primary-dark);
        }

        /* Team Modal Responsive */
        @media (max-width: 768px) {
            .hp-team-modal-header {
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 20px 15px;
                gap: 15px;
            }

            .hp-team-modal-img {
                width: 90px;
                height: 90px;
                border: 3px solid var(--accent-yellow);
            }

            .hp-team-modal-info {
                align-items: center;
                width: 100%;
            }

            .hp-team-modal-info h2 {
                font-size: 22px;
            }

            .hp-team-modal-info .hp-team-role {
                font-size: 11px;
                margin-bottom: 12px;
            }

            .hp-team-contact {
                width: 100%;
                align-items: stretch;
                gap: 8px;
            }

            .hp-team-contact-item {
                width: 100%;
                justify-content: center;
                font-size: 12px;
                padding: 8px 12px;
            }

            .hp-team-header-cta {
                width: 100%;
                justify-content: center;
                font-size: 12px;
                padding: 8px 12px;
            }

            .hp-team-modal-body {
                padding: 20px 15px;
            }

            .hp-team-modal-intro {
                padding: 15px;
                font-size: 13px;
            }

            .hp-team-modal-bio h3 {
                font-size: 18px;
                margin-bottom: 12px;
            }

            .hp-team-modal-bio p {
                font-size: 13px;
                line-height: 1.5;
                margin-bottom: 12px;
            }

            .hp-team-modal-bio ul {
                padding: 15px;
            }

            .hp-team-modal-bio ul li {
                font-size: 13px;
                padding-left: 20px;
                margin-bottom: 8px;
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .hp-team-modal-header {
                padding: 25px;
                gap: 20px;
            }

            .hp-team-modal-img {
                width: 140px;
                height: 140px;
            }

            .hp-team-modal-info h2 {
                font-size: 28px;
            }

            .hp-team-modal-info .hp-team-role {
                font-size: 13px;
            }

            .hp-team-contact-item,
            .hp-team-header-cta {
                font-size: 13px;
            }

            .hp-team-modal-body {
                padding: 30px 25px;
            }
        }

        /* Resources Section */
        .hp-resources {
            background: transparent;
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 5px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hp-resources h2 {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 15px;
            text-align: center;
            font-family: var(--font-titles);
        }

        .hp-resources-subtitle {
            font-size: 16px;
            color: var(--primary-dark);
            text-align: center;
            margin-bottom: 40px;
            opacity: 0.8;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .hp-resources-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .hp-resource-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            gap: 20px;
            position: relative;
            border: 2px solid transparent;
        }

        .hp-resource-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            border-color: var(--accent-yellow);
        }

        .hp-resource-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-dark) 0%, #5a4a6a 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }

        .hp-resource-content {
            flex-grow: 1;
        }

        .hp-resource-content h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 10px;
            font-family: var(--font-titles);
            line-height: 1.3;
        }

        .hp-resource-content p {
            font-size: 14px;
            color: var(--primary-dark);
            opacity: 0.8;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .hp-resource-meta {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .hp-resource-format {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .hp-resource-download {
            background: var(--primary-dark);
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            align-self: flex-start;
            font-family: var(--font-titles);
        }

        .hp-resource-download:hover {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            transform: translateX(3px);
        }

        .hp-resource-download i {
            flex-shrink: 0;
        }

        /* Resources Responsive */
        @media (max-width: 768px) {
            .hp-resources {
                padding: 30px 20px;
            }

            .hp-resources h2 {
                font-size: 32px;
            }

            .hp-resources-subtitle {
                font-size: 14px;
                margin-bottom: 30px;
            }

            .hp-resources-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .hp-resource-card {
                padding: 20px;
            }

            .hp-resource-icon {
                width: 60px;
                height: 60px;
            }

            .hp-resource-icon i {
                width: 32px !important;
                height: 32px !important;
            }

            .hp-resource-content h3 {
                font-size: 16px;
            }

            .hp-resource-content p {
                font-size: 13px;
            }

            .hp-resource-download {
                width: 100%;
                justify-content: center;
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .hp-resources-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Resources Section - List Styles */
        .hp-resources-list {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        @media (max-width: 768px) {
            .hp-resources-list {
                grid-template-columns: 1fr;
            }
        }

        .hp-accordion-resource {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
            transition: all 0.2s ease;
        }

        .hp-accordion-resource:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        .hp-accordion-resource-info {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            flex: 1;
        }

        .hp-accordion-resource-info i {
            margin-top: 2px;
            flex-shrink: 0;
        }

        .hp-accordion-resource-info h4 {
            font-size: 15px;
            font-weight: 700;
            color: var(--primary-dark);
            margin: 0 0 4px 0;
            font-family: var(--font-titles);
        }

        .hp-accordion-resource-info p {
            font-size: 13px;
            color: var(--primary-dark);
            opacity: 0.7;
            margin: 0;
            line-height: 1.4;
        }

        .hp-accordion-resource-meta {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .hp-accordion-download {
            background: var(--primary-dark);
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            text-decoration: none;
            flex-shrink: 0;
        }

        .hp-accordion-download:hover {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            transform: scale(1.1);
        }

        /* Resources Responsive */
        @media (max-width: 768px) {
            .hp-resources-list {
                gap: 15px;
            }

            .hp-accordion-resource {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                padding: 12px;
            }

            .hp-accordion-resource-meta {
                width: 100%;
                justify-content: flex-start;
                gap: 15px;
            }

            .hp-resource-format {
                font-size: 10px;
                padding: 3px 8px;
            }

            .hp-accordion-download {
                width: 32px;
                height: 32px;
            }

            .hp-accordion-download i {
                width: 16px !important;
                height: 16px !important;
            }

            .hp-accordion-resource-list {
                padding: 10px 15px 15px;
            }
        }

        /* FAQs Section */
        .hp-faqs {
            padding: 60px 40px;
            background: transparent;
            margin-bottom: 40px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hp-faqs h2 {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            text-align: center;
            margin-bottom: 50px;
            font-family: var(--font-titles);
        }

        .hp-faqs-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .hp-faq-item {
            background: white;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .hp-faq-item:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
        }

        .hp-faq-question {
            width: 100%;
            background: white !important;
            border: none;
            padding: 20px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: left;
            outline: none;
        }

        .hp-faq-question:hover {
            background: #f8f8f8 !important;
        }

        .hp-faq-question:focus {
            background: white !important;
            outline: none;
        }

        .hp-faq-item.active .hp-faq-question {
            background: white !important;
        }

        .hp-faq-question-text {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-dark);
            font-family: var(--font-titles);
            flex: 1;
            padding-right: 20px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .hp-faq-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--accent-yellow);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .hp-faq-icon i {
            color: var(--primary-dark);
            transition: transform 0.3s ease;
        }

        .hp-faq-item.active .hp-faq-icon i {
            transform: rotate(180deg);
        }

        .hp-faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease;
        }

        .hp-faq-item.active .hp-faq-answer {
            max-height: 800px;
        }

        .hp-faq-answer-content {
            padding: 0 25px 25px;
            color: var(--primary-dark);
            font-size: 15px;
            line-height: 1.7;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .hp-faq-answer-content p {
            margin-bottom: 12px;
        }

        .hp-faq-answer-content ul {
            margin: 12px 0;
            padding-left: 20px;
        }

        .hp-faq-answer-content ul li {
            margin-bottom: 8px;
            line-height: 1.6;
        }

        /* FAQs Responsive */
        @media (max-width: 768px) {
            .hp-faqs {
                padding: 30px 15px;
                margin-bottom: 25px;
                overflow-x: hidden;
            }

            .hp-faqs h2 {
                font-size: 26px;
                margin-bottom: 20px;
                padding: 0 5px;
            }

            .hp-faqs-container {
                max-width: 100%;
                padding: 0;
                margin: 0;
            }

            .hp-faq-item {
                margin-bottom: 10px;
                border-radius: 8px;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            .hp-faq-question {
                padding: 14px 12px;
                width: 100%;
                box-sizing: border-box;
                gap: 10px;
                align-items: flex-start;
            }

            .hp-faq-question-text {
                font-size: 13px;
                padding-right: 0;
                line-height: 1.5;
                word-break: break-word;
                white-space: normal;
                flex: 1;
                min-width: 0;
                max-width: calc(100% - 32px);
            }

            .hp-faq-icon {
                width: 22px;
                height: 22px;
                flex-shrink: 0;
                min-width: 22px;
                margin-top: 2px;
            }

            .hp-faq-icon i {
                width: 12px !important;
                height: 12px !important;
            }

            .hp-faq-answer {
                width: 100%;
                box-sizing: border-box;
            }

            .hp-faq-answer-content {
                padding: 0 12px 12px;
                font-size: 12px;
                line-height: 1.5;
                word-break: break-word;
            }

            .hp-faq-answer-content p {
                margin-bottom: 8px;
                word-break: break-word;
            }

            .hp-faq-answer-content ul {
                margin: 8px 0;
                padding-left: 16px;
            }

            .hp-faq-answer-content ul li {
                margin-bottom: 5px;
                font-size: 11px;
                word-break: break-word;
            }
        }

        /* CTA Section Card */
        .hp-cta-section {
            background: transparent;
            border-radius: 15px;
            padding: 50px 40px;
            text-align: center;
            margin-bottom: 5px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hp-cta-section h2 {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 20px;
            font-family: var(--font-titles);
            text-align: center;
        }

        .hp-cta-section h2 span {
            color: var(--accent-red);
        }

        .hp-cta-section p {
            font-size: 16px;
            color: var(--primary-dark);
            margin-bottom: 35px;
            max-width: 550px;
            margin-left: auto;
            margin-right: auto;
        }

        .hp-cta-button {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 14px 36px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-family: var(--font-titles);
            text-transform: uppercase;
        }

        .hp-cta-button:hover {
            background: var(--white);
            transform: translateY(-3px);
        }

        /* Accreditations Section */
        .hp-accreditations {
            background: transparent;
            border-radius: 15px;
            padding: 20px 40px 50px;
            text-align: center;
            margin-bottom: 5px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hp-accreditations h2 {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 5px;
            font-family: var(--font-titles);
        }

        .hp-accreditations-img-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: center;
        }

        .hp-accreditations-img {
            width: 100%;
            max-width: 1400px;
            height: auto;
            display: block;
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
        }

        @media (max-width: 768px) {
            .hp-accreditations {
                padding: 30px 20px;
                overflow-x: hidden;
            }

            .hp-accreditations h2 {
                font-size: 28px;
                margin-bottom: 25px;
            }

            .hp-accreditations-img-container {
                max-width: 100%;
                padding: 0;
                margin: 0;
            }

            .hp-accreditations-img {
                width: 100%;
                max-width: 100%;
            }
        }

        /* ========================================
           OPCIONES PARA DESTACAR LA SECCIÓN DE CONTACTO
           Descomenta la opción que prefieras
        ======================================== */

        /* OPCIÓN 1: Fondo degradado púrpura con bordes redondeados y sombra brillante */
        /* .hp-contact-section {
            padding: 70px 40px !important;
            background: linear-gradient(135deg, var(--primary-dark) 0%, #8a7aa0 50%, #9d8fb3 100%);
            border-radius: 20px !important;
            box-shadow: 0 15px 50px rgba(175, 160, 200, 0.5), 0 0 80px rgba(251, 186, 58, 0.3) !important;
            border: 3px solid var(--accent-yellow);
        }
        .hp-contact-section h2,
        .hp-contact-section .hp-contact-intro,
        .hp-contact-input-group label,
        .hp-contact-methods-intro,
        .hp-contact-info {
            color: white !important;
        }
        .hp-contact-section h2 span {
            color: var(--accent-yellow) !important;
        } */

        /* OPCIÓN 2: Fondo amarillo vibrante con patrón de puntos */
        .hp-contact-section {
            padding: 70px 40px !important;
            background: linear-gradient(135deg, var(--accent-yellow) 0%, #ffd97d 100%);
            border-radius: 25px !important;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1) !important;
            position: relative;
            overflow: hidden;
        }
        .hp-contact-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.3) 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.5;
        }
        .hp-contact-section * {
            position: relative;
            z-index: 1;
        }
        .hp-contact-section h2,
        .hp-contact-section .hp-contact-intro,
        .hp-contact-input-group label,
        .hp-contact-methods-intro {
            color: var(--primary-dark) !important;
        }
        .hp-contact-section h2 {
            text-align: center;
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 20px;
            font-family: var(--font-titles);
        }

        /* OPCIÓN 3: Borde grueso amarillo animado con fondo blanco destacado */
        /* .hp-contact-section {
            padding: 70px 40px !important;
            background: white;
            border-radius: 20px !important;
            border: 6px solid var(--accent-yellow);
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.15),
                        0 0 0 12px rgba(251, 186, 58, 0.1),
                        inset 0 0 50px rgba(251, 186, 58, 0.05) !important;
            position: relative;
            animation: glow-border 3s ease-in-out infinite;
        }

        @keyframes glow-border {
            0%, 100% {
                box-shadow: 0 25px 70px rgba(0, 0, 0, 0.15),
                            0 0 0 12px rgba(251, 186, 58, 0.1),
                            inset 0 0 50px rgba(251, 186, 58, 0.05);
            }
            50% {
                box-shadow: 0 25px 70px rgba(0, 0, 0, 0.15),
                            0 0 0 12px rgba(251, 186, 58, 0.3),
                            inset 0 0 50px rgba(251, 186, 58, 0.1);
            }
        }

        .hp-contact-section::after {
            content: '✨ Contact Us ✨';
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, var(--accent-yellow) 0%, #ffc857 100%);
            color: var(--primary-dark);
            padding: 8px 30px;
            border-radius: 50px;
            font-weight: 900;
            font-size: 14px;
            letter-spacing: 1px;
            box-shadow: 0 4px 15px rgba(251, 186, 58, 0.4);
            font-family: var(--font-titles);
        } */

        /* OPCIÓN 1: Título con icono de sobre y subrayado amarillo */
        .hp-contact-title-option1 {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 20px;
            font-family: var(--font-titles);
        }

        .hp-contact-title-option1 i {
            color: var(--accent-yellow);
        }

        .hp-contact-highlight {
            position: relative;
            color: var(--primary-dark);
        }

        .hp-contact-highlight::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            right: 0;
            height: 8px;
            background: var(--accent-yellow);
            z-index: -1;
        }

        /* OPCIÓN 2: Título todo en mayúsculas con fondo amarillo */
        .hp-contact-title-option2 {
            font-size: 48px;
            font-weight: 900;
            color: white;
            background: linear-gradient(135deg, var(--accent-yellow) 0%, #ffc857 100%);
            padding: 25px 60px;
            border-radius: 15px;
            display: inline-block;
            margin: 0 auto 20px;
            font-family: var(--font-titles);
            letter-spacing: 3px;
            box-shadow: 0 8px 30px rgba(251, 186, 58, 0.4);
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* OPCIÓN 3: Título con badge amarillo y animación */
        .hp-contact-title-option3 {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 20px;
            font-family: var(--font-titles);
        }

        .hp-contact-badge {
            background: linear-gradient(135deg, var(--accent-yellow) 0%, #ffc857 100%);
            color: var(--primary-dark);
            padding: 8px 25px;
            border-radius: 50px;
            font-weight: 900;
            display: inline-block;
            margin-left: 10px;
            box-shadow: 0 4px 20px rgba(251, 186, 58, 0.4);
            animation: pulse-badge 2s ease-in-out infinite;
        }

        @keyframes pulse-badge {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 4px 20px rgba(251, 186, 58, 0.4);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 6px 30px rgba(251, 186, 58, 0.6);
            }
        }

        .hp-contact-intro {
            font-size: 18px;
            color: var(--primary-dark);
            margin-bottom: 45px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .hp-contact-form-grid {
            max-width: 900px;
            margin: 0 auto 50px;
        }

        .hp-contact-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .hp-contact-input-group {
            display: flex;
            flex-direction: column;
        }

        .hp-contact-full-width {
            grid-column: 1 / -1;
        }

        .hp-contact-input-group label {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 10px;
            font-family: var(--font-titles);
        }

        .hp-contact-input-group input,
        .hp-contact-input-group textarea {
            padding: 14px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            font-family: var(--font-body);
            transition: all 0.3s ease;
            background: white;
        }

        .hp-contact-input-group input:focus,
        .hp-contact-input-group textarea:focus {
            outline: none;
            border-color: var(--accent-yellow);
            box-shadow: 0 0 0 3px rgba(251, 186, 58, 0.1);
        }

        .hp-contact-input-group textarea {
            resize: vertical;
            min-height: 130px;
        }

        .hp-contact-submit {
            margin: 35px auto 0;
            display: block;
            background: var(--primary-dark) !important;
            color: var(--accent-yellow) !important;
            border: none;
            font-weight: 700;
            font-size: 18px;
            padding: 18px 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(107, 91, 123, 0.4);
        }

        .hp-contact-submit:hover {
            background: #5a4d6b !important;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(107, 91, 123, 0.6);
        }

        .hp-enrollment-section {
            max-width: 800px;
            margin: 40px auto 0;
            padding: 30px;
            background: rgba(175, 160, 200, 0.1);
            border-radius: 15px;
            text-align: center;
            border: 2px solid #8a7aa0;
        }

        .hp-enrollment-section p {
            font-size: 16px;
            color: var(--primary-dark);
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .hp-enrollment-button {
            display: inline-block;
            background: var(--primary-dark) !important;
            color: var(--accent-yellow) !important;
            padding: 15px 40px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(107, 91, 123, 0.4);
            font-family: var(--font-titles);
        }

        .hp-enrollment-button:hover {
            background: #5a4d6b !important;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(107, 91, 123, 0.6);
        }

        .hp-contact-methods-section {
            max-width: 800px;
            margin: 50px auto 0;
            padding-top: 50px;
            border-top: 2px solid rgba(107, 91, 123, 0.1);
        }

        .hp-contact-methods-intro {
            font-size: 16px;
            color: var(--primary-dark);
            text-align: center;
            margin-bottom: 30px;
        }

        .hp-contact-method-buttons {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }

        .hp-contact-method-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            padding: 25px 20px;
            background: white;
            border: 2px solid rgba(107, 91, 123, 0.2);
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .hp-contact-method-link i {
            color: var(--accent-yellow);
            transition: all 0.3s ease;
        }

        .hp-contact-method-link span {
            font-size: 14px;
            font-weight: 600;
            color: var(--primary-dark);
            font-family: var(--font-titles);
            text-align: center;
        }

        .hp-contact-method-link:hover {
            transform: translateY(-5px);
            border-color: var(--accent-yellow);
            box-shadow: 0 8px 25px rgba(251, 186, 58, 0.2);
        }

        .hp-contact-method-link:hover i {
            transform: scale(1.1);
        }

        .hp-contact-info {
            text-align: center;
            font-size: 15px;
            color: var(--primary-dark);
            line-height: 1.8;
            background: rgba(251, 186, 58, 0.1);
            padding: 20px 30px;
            border-radius: 10px;
        }

        .hp-contact-info strong {
            color: var(--primary-dark);
            font-weight: 700;
        }

        /* Enrollment Form Modal */
        .hp-enrollment-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            overflow-y: auto;
            padding: 20px;
        }

        .hp-enrollment-modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hp-enrollment-modal-content {
            background: white;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .hp-enrollment-modal-header {
            position: sticky;
            top: 0;
            background: white;
            z-index: 100;
            padding: 30px 40px 20px;
            border-bottom: 2px solid #f0f0f0;
        }

        .hp-enrollment-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: var(--primary-dark);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .hp-enrollment-close:hover {
            background: rgba(175, 160, 200, 0.1);
            transform: rotate(90deg);
        }

        .hp-enrollment-modal-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 25px;
            font-family: var(--font-titles);
        }

        /* Progress Bar */
        .hp-enrollment-progress {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 10px;
            position: relative;
            padding: 0 15px;
        }

        .hp-enrollment-progress::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 15px;
            right: 15px;
            height: 2px;
            background: #e0e0e0;
            z-index: 1;
        }

        .hp-enrollment-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
            z-index: 2;
            pointer-events: none;
            user-select: none;
        }

        .hp-enrollment-step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e0e0e0;
            border: 3px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            transition: background 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
        }

        .hp-enrollment-step.active .hp-enrollment-step-circle {
            background: var(--accent-yellow);
            box-shadow: 0 2px 12px rgba(251, 186, 58, 0.5);
        }

        .hp-enrollment-step.completed .hp-enrollment-step-circle {
            background: var(--accent-yellow);
            box-shadow: 0 2px 12px rgba(251, 186, 58, 0.5);
        }

        .hp-enrollment-step-label {
            font-size: 11px;
            color: #999;
            text-align: center;
            font-weight: 600;
            line-height: 1.2;
            max-width: 80px;
            min-height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hp-enrollment-step.active .hp-enrollment-step-label {
            color: #4a90e2;
            font-weight: 700;
        }

        .hp-enrollment-step.completed .hp-enrollment-step-label {
            color: var(--primary-purple);
            font-weight: 700;
        }

        /* Modal Body */
        .hp-enrollment-modal-body {
            padding: 40px;
        }

        .hp-enrollment-form-step {
            display: none;
        }

        .hp-enrollment-form-step.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hp-enrollment-form-group {
            margin-bottom: 25px;
        }

        .hp-enrollment-form-group label {
            display: block;
            font-size: 16px;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 8px;
            font-family: var(--font-titles);
        }

        .hp-enrollment-form-group input,
        .hp-enrollment-form-group select,
        .hp-enrollment-form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            font-family: var(--font-body);
            transition: all 0.3s ease;
        }

        .hp-enrollment-form-group input:focus,
        .hp-enrollment-form-group select:focus,
        .hp-enrollment-form-group textarea:focus {
            outline: none;
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(175, 160, 200, 0.1);
        }

        .hp-enrollment-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* Course Options - Selectable Rectangles */
        .hp-course-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 10px;
        }

        .hp-course-option {
            padding: 14px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            color: var(--primary-dark);
            text-align: center;
            user-select: none;
        }

        .hp-course-option:hover {
            border-color: var(--primary-purple);
            background: rgba(175, 160, 200, 0.05);
        }

        .hp-course-option.selected {
            border-color: var(--accent-yellow);
            background: var(--accent-yellow);
            color: var(--primary-dark);
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(251, 186, 58, 0.3);
        }

        /* Featured Course Option - Removed (now using default styling) */

        /* Course Categories */
        .hp-course-category {
            margin-bottom: 20px;
        }

        .hp-course-category-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--primary-purple);
            margin: 0 0 12px 0;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--primary-purple);
        }

        /* Modal Footer */
        .hp-enrollment-modal-footer {
            padding: 20px 40px 40px;
            display: flex;
            justify-content: space-between;
            gap: 15px;
        }

        .hp-enrollment-btn {
            padding: 14px 35px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-family: var(--font-titles);
        }

        .hp-enrollment-btn-secondary {
            background: #e0e0e0;
            color: var(--primary-dark);
        }

        .hp-enrollment-btn-secondary:hover {
            background: #d0d0d0;
        }

        .hp-enrollment-btn-primary {
            background: var(--primary-dark);
            color: var(--accent-yellow);
            box-shadow: 0 4px 15px rgba(107, 91, 123, 0.4);
        }

        .hp-enrollment-btn-primary:hover {
            background: #5a4d6b;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(107, 91, 123, 0.6);
        }

        /* Housing Options - Selectable Rectangles */
        .hp-housing-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 10px;
        }

        .hp-housing-option {
            padding: 18px 24px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 16px;
            font-weight: 600;
            color: var(--primary-dark);
            text-align: center;
            user-select: none;
        }

        .hp-housing-option:hover {
            border-color: var(--primary-purple);
            background: rgba(175, 160, 200, 0.05);
        }

        .hp-housing-option.selected {
            border-color: var(--accent-yellow);
            background: var(--accent-yellow);
            color: var(--primary-dark);
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(251, 186, 58, 0.3);
        }

        /* File Upload Styles */
        .hp-file-upload-container {
            width: 100%;
        }

        .hp-file-upload-box {
            border: 2px dashed #e0e0e0;
            border-radius: 12px;
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #fafafa;
        }

        .hp-file-upload-box:hover {
            border-color: var(--primary-purple);
            background: #f5f0ff;
        }

        .hp-file-upload-box span {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .hp-file-upload-box small {
            font-size: 12px;
        }

        .hp-file-preview {
            border: 2px solid #4caf50;
            border-radius: 12px;
            padding: 15px;
            background: #f1f8e9;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .hp-file-preview img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #ddd;
        }

        .hp-file-preview-info {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .hp-file-preview-info span {
            color: #333;
            font-size: 14px;
            font-weight: 500;
            word-break: break-all;
        }

        .hp-file-remove-btn {
            background: #ff5252;
            color: white;
            border: none;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease;
        }

        .hp-file-remove-btn:hover {
            background: #d32f2f;
        }

        .hp-file-pdf-icon {
            width: 80px;
            height: 80px;
            background: #e53935;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }

        /* Housing Type Categories */
        .hp-housing-type-category {
            margin-bottom: 25px;
        }

        .hp-housing-category-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-purple);
            margin: 0 0 12px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-purple);
        }

        .hp-housing-type-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 10px;
        }

        .hp-housing-type-option {
            padding: 14px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 15px;
            font-weight: 500;
            color: var(--primary-dark);
            text-align: left;
            user-select: none;
        }

        .hp-housing-type-option:hover {
            border-color: var(--primary-purple);
            background: rgba(175, 160, 200, 0.05);
            transform: translateX(4px);
        }

        .hp-housing-type-option.selected {
            border-color: var(--accent-yellow);
            background: var(--accent-yellow);
            color: var(--primary-dark);
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(251, 186, 58, 0.3);
        }

        /* Add Course Button */
        .hp-add-course-btn {
            width: 100%;
            padding: 16px 24px;
            background: white;
            color: var(--primary-purple);
            border: 3px dashed var(--primary-purple);
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 25px;
            font-family: var(--font-titles);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(175, 160, 200, 0.15);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .hp-add-course-btn:hover {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            border-color: var(--accent-yellow);
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(251, 186, 58, 0.4);
        }

        .hp-add-course-btn:active {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(251, 186, 58, 0.3);
        }

        /* Additional Course Container */
        .hp-additional-course {
            margin-top: 30px;
            padding: 25px;
            border: 2px solid var(--accent-yellow);
            border-radius: 12px;
            background: rgba(251, 186, 58, 0.05);
            position: relative;
        }

        .hp-remove-course-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #ff4444;
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            cursor: pointer;
            font-size: 18px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .hp-remove-course-btn:hover {
            background: #cc0000;
            transform: scale(1.1);
        }

        /* Insurance cards grid */
        .hp-insurance-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 10px;
        }

        /* Summary grid */
        .hp-summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        /* Enrollment Form Responsive Styles */
        @media (max-width: 768px) {
            /* Modal - full screen on mobile */
            .hp-enrollment-modal {
                padding: 0;
            }

            .hp-enrollment-modal-content {
                width: 100%;
                height: 100vh;
                max-height: 100vh;
                margin: 0;
                border-radius: 0;
                padding: 15px;
                display: flex;
                flex-direction: column;
            }

            .hp-enrollment-modal-header {
                padding: 15px 0 10px;
                margin-bottom: 15px;
                flex-shrink: 0;
            }

            .hp-enrollment-modal-title {
                font-size: 20px;
                padding-right: 30px;
            }

            .hp-enrollment-close {
                font-size: 32px;
                width: 32px;
                height: 32px;
                top: 10px;
                right: 10px;
            }

            .hp-enrollment-modal-body {
                flex: 1;
                overflow-y: auto;
                padding: 0;
                margin-bottom: 15px;
            }

            .hp-enrollment-modal-footer {
                flex-shrink: 0;
                padding: 15px 0 10px;
                margin-top: auto;
            }

            /* Progress bar - completely redesigned for mobile */
            .hp-enrollment-progress {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                padding: 0;
                margin-bottom: 20px;
                gap: 0;
            }

            .hp-enrollment-step {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                min-width: 0;
                padding: 0 2px;
            }

            .hp-enrollment-step-circle {
                width: 28px;
                height: 28px;
                min-width: 28px;
                min-height: 28px;
                font-size: 12px;
                font-weight: 700;
                border-width: 2px;
                margin-bottom: 6px;
                flex-shrink: 0;
            }

            .hp-enrollment-step-label {
                font-size: 9px;
                line-height: 1.2;
                text-align: center;
                word-break: break-word;
                hyphens: auto;
                max-width: 100%;
                padding: 0 1px;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }

            /* Form rows - stack vertically on mobile */
            .hp-enrollment-form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            /* Course options - one column on mobile */
            .hp-course-options {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .hp-course-option {
                padding: 12px 16px;
                font-size: 14px;
            }

            /* Form inputs */
            .hp-enrollment-form-group {
                margin-bottom: 20px;
            }

            .hp-enrollment-form-group label {
                font-size: 15px;
                margin-bottom: 6px;
            }

            .hp-enrollment-form-group input,
            .hp-enrollment-form-group select,
            .hp-enrollment-form-group textarea {
                font-size: 16px; /* Prevents zoom on iOS */
                padding: 12px;
            }

            /* Add course button */
            .hp-add-course-btn {
                padding: 14px 20px;
                font-size: 15px;
            }

            /* Navigation buttons */
            .hp-enrollment-nav-btn {
                padding: 14px 28px;
                font-size: 15px;
            }

            /* Housing Type Categories - Mobile */
            .hp-housing-type-category {
                margin-bottom: 20px;
            }

            .hp-housing-category-title {
                font-size: 15px;
                padding-bottom: 6px;
                margin-bottom: 10px;
            }

            .hp-housing-type-option {
                padding: 12px 16px;
                font-size: 14px;
            }

            /* Insurance cards - 1 column on mobile */
            .hp-insurance-grid {
                grid-template-columns: 1fr;
            }

            /* Summary - 1 column on mobile */
            .hp-summary-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .hp-contact-section {
                padding: 40px 20px !important;
            }

            .hp-contact-section h2 {
                font-size: 28px;
            }

            .hp-contact-intro {
                font-size: 15px;
                margin-bottom: 30px;
            }

            .hp-contact-input-group label {
                font-size: 14px;
            }

            .hp-contact-input-group input,
            .hp-contact-input-group textarea {
                font-size: 14px;
                padding: 12px 15px;
            }

            .hp-contact-form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .hp-contact-method-buttons {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .hp-contact-submit {
                width: 100%;
                font-size: 16px;
                padding: 14px 35px;
            }
        }

        /* Footer Card */
        .hp-footer {
            background: transparent;
            border-radius: 15px;
            padding: 35px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hp-footer-content {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 50px;
            color: var(--primary-dark);
        }

        .hp-footer-logo {
            height: 35px;
            width: auto;
            display: block;
        }

        .hp-footer-brand-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .hp-footer-brand-header span {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-purple);
            font-family: var(--font-titles);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .hp-footer-brand h3 {
            font-size: 22px;
            margin-bottom: 15px;
            font-family: var(--font-titles);
        }

        .hp-footer-brand p {
            font-size: 14px;
            opacity: 0.7;
            line-height: 1.7;
        }

        .hp-footer-links h4 {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 18px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: var(--font-titles);
        }

        .hp-footer-links ul {
            list-style: none;
        }

        .hp-footer-links li {
            margin-bottom: 10px;
            font-size: 14px;
            opacity: 0.7;
            color: var(--primary-dark);
        }

        .hp-footer-links a {
            color: var(--primary-dark);
            text-decoration: none;
            font-size: 14px;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }

        .hp-footer-links a:hover {
            opacity: 1;
        }

        .hp-footer-bottom {
            margin-top: 40px;
            padding-top: 25px;
            border-top: 1px solid rgba(18,5,95,0.2);
            text-align: center;
            color: var(--primary-dark);
            font-size: 13px;
            opacity: 0.7;
        }

        /* Responsive */
        @media (max-width: 1400px) and (min-width: 1201px) {
            .hp-hero {
                gap: 40px;
            }
            .hp-hero-partner-img {
                width: 110%;
                max-width: 700px;
            }
            .hp-hero-mosaic {
                grid-template-columns: repeat(2, 180px);
                grid-template-rows: 150px 100px;
                gap: 12px;
            }
            .hp-hero-description {
                font-size: 16px;
            }
        }

        @media (max-width: 1200px) and (min-width: 1025px) {
            .hp-hero {
                gap: 30px;
                grid-template-columns: 1.2fr 1fr;
            }
            .hp-hero-partner-img {
                width: 100%;
                max-width: 500px;
            }
            .hp-hero-mosaic {
                grid-template-columns: repeat(2, 160px);
                grid-template-rows: 130px 90px;
                gap: 10px;
            }
            .hp-hero-description {
                font-size: 18px !important;
                max-width: 100% !important;
                text-align: center !important;
                font-weight: 700 !important;
                line-height: 1.3 !important;
                letter-spacing: 2px !important;
                text-transform: uppercase !important;
                font-family: var(--font-titles) !important;
                padding: 0 !important;
                margin: 0 auto 10px auto !important;
            }
        }

        @media (max-width: 1024px) {
            .hp-main {
                padding: 3px 8px !important;
            }
            .hp-hero {
                grid-template-columns: 1fr;
                gap: 30px;
                min-height: 320px !important;
                padding: 20px 15px !important;
                padding-bottom: 80px !important;
            }
            .hp-hero-partner-img {
                width: 100%;
                max-width: 600px;
                margin: 0 auto;
            }
            .hp-hero-title-container {
                text-align: center;
                padding: 15px 0 !important;
                height: 100px !important;
                max-width: 100% !important;
            }
            .hp-hero-content {
                align-items: center;
                gap: 15px;
            }
            .hp-hero-right {
                align-items: center;
            }
            .hp-hero-subtitle {
                font-size: 16px !important;
                margin-top: 10px !important;
                letter-spacing: 2px !important;
            }
            .hp-hero-description {
                max-width: 100% !important;
                font-size: 22px !important;
                text-align: center !important;
                font-weight: 700 !important;
                line-height: 1.3 !important;
                letter-spacing: 3px !important;
                text-transform: uppercase !important;
                font-family: var(--font-titles) !important;
                padding: 0 !important;
                margin: 0 auto 10px auto !important;
            }
            .hp-hero-cta-bottom {
                bottom: 20px;
                left: 15px;
            }
            .hp-hero-mosaic {
                display: grid;
                grid-template-columns: repeat(2, 220px);
                grid-template-rows: 180px 120px;
                gap: 12px;
                margin: 0 auto;
            }
            .hp-programs-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            .hp-program-card img {
                height: 180px;
                object-position: center 20%;
            }
            .hp-accordion-item {
                margin-top: -10px;
                border-radius: 12px;
            }
            .hp-accordion-header {
                padding: 18px 20px;
            }
            .hp-accordion-number {
                font-size: 18px;
                margin-right: 20px;
                min-width: 35px;
            }
            .hp-accordion-title {
                font-size: 15px;
            }
            .hp-accordion-icon {
                font-size: 20px;
            }
            .hp-accordion-body {
                padding: 0 20px 20px 75px;
            }
            .hp-accordion-body h4 {
                font-size: 16px;
                margin-bottom: 10px;
            }
            .hp-accordion-body p {
                font-size: 13px;
                line-height: 1.6;
            }
            .hp-hero, .hp-what-built, .hp-advantages, .hp-programs, .hp-cta-section, .hp-footer {
                padding: 20px 15px !important;
                border-radius: 12px !important;
                margin-bottom: 3px !important;
            }
            .hp-header, .hp-hero, .hp-what-built, .hp-advantages, .hp-programs, .hp-cta-section, .hp-footer {
                margin-bottom: 3px;
            }
            .hp-what-built h2, .hp-advantages h2, .hp-programs h2, .hp-cta-section h2 {
                font-size: 24px !important;
            }
            .hp-what-built {
                padding: 20px 15px !important;
            }
            .hp-advantages-desc {
                font-size: 14px !important;
            }
            .hp-program-card h3 {
                font-size: 18px !important;
            }
            .hp-program-card p {
                font-size: 13px !important;
            }
            .hp-cta-section h2 {
                font-size: 24px !important;
            }
            .hp-cta-section p {
                font-size: 14px !important;
            }
            .hp-footer-content {
                grid-template-columns: 1fr;
                gap: 25px;
            }
            .hp-footer-logo {
                height: 28px;
                width: auto;
            }
            .hp-footer-brand-header span {
                font-size: 14px;
            }
            .hp-menu-toggle {
                display: block !important;
            }
            .hp-nav {
                display: none !important;
            }
            .hp-header {
                padding: 10px 15px !important;
                border-radius: 12px !important;
                margin-bottom: 3px !important;
                background: rgba(255, 255, 255, 0.5) !important;
            }
            .hp-logo {
                order: 1 !important;
                flex: 0 0 auto !important;
            }
            .hp-logo-img {
                height: 28px !important;
            }
            .hp-logo span {
                font-size: 14px !important;
            }
            .hp-header-right {
                order: 2 !important;
                flex: 1 !important;
                display: flex !important;
                justify-content: flex-end !important;
            }
            .hp-header-cta {
                padding: 6px 14px !important;
                font-size: 10px !important;
            }
            .hp-menu-toggle {
                order: 3 !important;
            }
            .hp-menu-toggle span {
                width: 22px !important;
                height: 2.5px !important;
                margin: 3.5px 0 !important;
            }
            .hp-letter {
                transform: scaleY(1.2) translateY(-8px);
            }
            .hp-letter:hover {
                transform: scaleY(1.2) translateY(-18px);
            }
        }

        /* Mobile phones (iPhone XR, 12 Pro, 14, Samsung, etc.) */
        @media (max-width: 768px) {
            .hp-header-cta {
                padding: 5px 10px !important;
                font-size: 8px !important;
                border-radius: 5px !important;
                white-space: nowrap !important;
            }
            .hp-hero-mosaic {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
                grid-template-rows: 140px 100px;
                gap: 10px;
                margin: 0 auto;
                width: 100%;
                max-width: 320px;
            }
            .hp-programs-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .hp-program-card img {
                height: 220px;
                object-position: center 20%;
            }
            .hp-program-card-content {
                padding: 20px;
            }
            .hp-program-card h3 {
                font-size: 20px !important;
            }
            .hp-program-card p {
                font-size: 14px !important;
            }
        }

        @media (max-width: 480px) {
            .hp-main {
                padding: 2px 5px !important;
            }
            .hp-header {
                padding: 12px !important;
            }
            .hp-logo-img {
                height: 26px !important;
            }
            .hp-logo span {
                font-size: 14px !important;
            }
            .hp-nav a {
                font-size: 10px !important;
                padding: 4px 6px !important;
            }
            .hp-header-cta {
                padding: 8px 14px !important;
                font-size: 11px !important;
            }
            .hp-hero-title-container {
                height: 70px !important;
            }
            .hp-hero-description {
                font-size: 13px !important;
            }
            .hp-hero-subtitle {
                font-size: 14px !important;
                letter-spacing: 1px !important;
            }
            .hp-hero {
                min-height: 260px !important;
                padding: 15px 10px 70px !important;
            }
            .hp-hero-mosaic {
                grid-template-columns: repeat(2, 1fr);
                grid-template-rows: 120px 80px;
                gap: 8px;
                max-width: 280px;
            }
            .hp-advantages h2, .hp-programs h2, .hp-cta-section h2 {
                font-size: 24px;
            }
            .hp-accordion-header {
                font-size: 14px;
                padding: 12px 15px;
            }
            .hp-accordion-body {
                font-size: 13px;
                padding: 12px 15px;
            }
            .hp-cta-section p {
                font-size: 16px;
            }
            .hp-footer-content {
                font-size: 13px;
            }
            .hp-program-card img {
                height: 200px;
            }
        }

        /* Hamburger Menu Button */
        .hp-menu-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 5px;
            z-index: 1001;
        }

        .hp-menu-toggle span {
            display: block;
            width: 25px;
            height: 3px;
            background: var(--primary-dark);
            margin: 4px 0;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .hp-menu-toggle.active span:nth-child(1) {
            transform: rotate(45deg) translate(6px, 6px);
        }

        .hp-menu-toggle.active span:nth-child(2) {
            opacity: 0;
        }

        .hp-menu-toggle.active span:nth-child(3) {
            transform: rotate(-45deg) translate(6px, -6px);
        }

        /* Mobile Menu Overlay */
        .hp-mobile-menu-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .hp-mobile-menu-overlay.active {
            display: block;
            opacity: 1;
        }

        /* Mobile Menu */
        .hp-mobile-menu {
            position: fixed;
            top: 0;
            right: -100%;
            width: 60%;
            max-width: 220px;
            height: 100%;
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            z-index: 1000;
            padding: 80px 20px 30px;
            transition: right 0.3s ease;
            box-shadow: -2px 0 10px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }

        .hp-mobile-menu.active {
            right: 0;
        }

        .hp-mobile-menu a {
            display: block;
            padding: 15px 0;
            color: var(--primary-dark);
            text-decoration: none;
            font-family: var(--font-titles);
            font-size: 16px;
            font-weight: 500;
            border-bottom: 1px solid #f0f0f0;
            transition: color 0.2s ease;
        }

        .hp-mobile-menu a:hover {
            color: var(--accent-yellow);
            font-weight: 700;
        }

        /* Modal Styles */
        .hp-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        }

        .hp-modal.active {
            display: block;
        }

        .hp-modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }

        .hp-modal-content {
            position: relative;
            background: white;
            max-width: 900px;
            width: 90%;
            max-height: 90vh;
            margin: 5vh auto;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 50px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.4s ease;
            display: flex;
            flex-direction: column;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .hp-modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: var(--primary-dark);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
            z-index: 10;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .hp-modal-close:hover {
            background: var(--accent-yellow);
            color: var(--primary-dark);
            transform: rotate(90deg);
        }

        .hp-modal-close:active,
        .hp-modal-close:focus {
            outline: none;
            transform: rotate(90deg) scale(0.95);
        }

        .hp-modal-close:focus-visible {
            outline: 2px solid var(--accent-yellow);
            outline-offset: 2px;
        }

        .hp-modal-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #5a4a6a 100%);
            color: white;
            padding: 40px 30px 30px;
        }

        .hp-modal-header h2 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 20px;
            font-family: var(--font-titles);
        }

        .hp-modal-highlights {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .hp-highlight {
            background: white;
            color: var(--primary-dark);
            padding: 10px 18px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .hp-highlight i {
            display: inline-block;
            vertical-align: middle;
            stroke: var(--primary-dark);
        }

        .hp-modal-tabs {
            display: flex;
            background: #f5f5f5;
            border-bottom: 2px solid #e0e0e0;
        }

        .hp-tab-btn {
            flex: 1;
            padding: 15px 20px;
            background: transparent;
            border: none;
            font-family: var(--font-titles);
            font-size: 16px;
            font-weight: 600;
            color: var(--primary-dark);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .hp-tab-btn:hover {
            background: rgba(105, 61, 53, 0.05);
        }

        .hp-tab-btn.active {
            background: var(--accent-yellow);
            color: var(--primary-dark);
        }

        .hp-tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--primary-dark);
        }

        .hp-modal-body {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .hp-tab-content {
            display: none;
        }

        .hp-tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .hp-modal-section {
            margin-bottom: 30px;
        }

        .hp-modal-section h3 {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-family: var(--font-titles);
        }

        .hp-modal-section p {
            font-size: 16px;
            line-height: 1.7;
            color: #555;
            margin-bottom: 15px;
        }

        .hp-objectives-list {
            list-style: none;
            padding: 0;
        }

        .hp-objectives-list li {
            font-size: 16px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
            color: #555;
        }

        .hp-objectives-list li:last-child {
            border-bottom: none;
        }

        /* Pathway Table */
        .hp-pathway-table {
            background: #f9f9f9;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .hp-pathway-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .hp-pathway-row:last-child {
            border-bottom: none;
        }

        .hp-pathway-header {
            background: var(--primary-dark);
            color: white;
            font-weight: 700;
            font-size: 16px;
        }

        .hp-pathway-header .hp-pathway-col.hp-price {
            justify-content: center;
        }

        .hp-pathway-col {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
        }

        .hp-pathway-col.hp-price {
            justify-content: center;
            align-items: center;
        }

        .hp-price {
            font-weight: 700;
            color: #e67e22;
            font-size: 18px;
        }

        .hp-pathway-note {
            font-size: 14px;
            color: #777;
            font-style: italic;
            margin-top: 10px;
        }

        .hp-pathway-col small {
            display: block;
            margin-top: 8px;
            line-height: 1.5;
            font-size: 13px;
        }

        .hp-pathway-col strong {
            display: block;
        }

        /* EADE Benefits */
        .hp-eade-benefits {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 20px;
        }

        .hp-benefit-card {
            background: #f9f9f9;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .hp-benefit-card:hover {
            background: var(--accent-yellow);
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .hp-benefit-icon {
            margin-bottom: 15px;
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--primary-dark);
        }

        .hp-benefit-icon i {
            stroke: var(--primary-dark);
        }

        .hp-benefit-card:hover .hp-benefit-icon i {
            stroke: var(--primary-dark);
        }

        .hp-benefit-card h4 {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 10px;
            font-family: var(--font-titles);
        }

        .hp-benefit-card p {
            font-size: 14px;
            color: #555;
            line-height: 1.5;
        }

        .hp-modal-footer {
            background: #f5f5f5;
            padding: 20px 30px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
        }

        .hp-modal-cta {
            display: inline-block;
            background: var(--accent-yellow);
            color: var(--primary-dark);
            padding: 15px 40px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-family: var(--font-titles);
        }

        .hp-modal-cta:hover {
            background: var(--primary-dark);
            color: white;
            transform: scale(1.05);
        }

        /* Mobile Modal Styles */
        @media (max-width: 1024px) {
            .hp-modal-content {
                width: 95%;
                max-height: 95vh;
                margin: 2.5vh auto;
            }

            .hp-modal-header h2 {
                font-size: 24px;
            }

            .hp-modal-highlights {
                gap: 10px;
            }

            .hp-highlight {
                font-size: 12px;
                padding: 6px 12px;
            }

            .hp-tab-btn {
                font-size: 14px;
                padding: 12px 10px;
            }

            .hp-modal-body {
                padding: 20px;
            }

            .hp-modal-section h3 {
                font-size: 20px;
            }

            .hp-modal-section p,
            .hp-objectives-list li {
                font-size: 14px;
            }

            .hp-pathway-row {
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 12px 15px;
            }

            .hp-pathway-col.hp-price {
                align-items: flex-start;
                padding: 8px 0;
                font-size: 16px;
            }

            .hp-pathway-col.hp-price::before {
                content: 'Price: ';
                font-weight: 600;
                color: var(--primary-dark);
                margin-right: 5px;
            }

            .hp-pathway-header .hp-pathway-col {
                display: none;
            }

            .hp-pathway-header .hp-pathway-col:first-child {
                display: flex;
            }

            .hp-eade-benefits {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .hp-modal-cta {
                padding: 12px 30px;
                font-size: 14px;
            }

            .hp-team-modal-header {
                flex-direction: column;
                gap: 20px;
            }

            .hp-team-modal-img {
                width: 150px;
                height: 150px;
                margin: 0 auto;
            }

            .hp-team-modal-info h2 {
                font-size: 24px;
                text-align: center;
            }

            .hp-team-modal-info .hp-team-role {
                font-size: 14px;
                text-align: center;
            }

            .hp-team-contact {
                align-items: center;
            }

            .hp-team-modal-bio h3 {
                font-size: 20px;
            }

            .hp-team-modal-bio p {
                font-size: 14px;
            }
        }

        /* Flatpickr Year Selector Styles */
        .flatpickr-current-month {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
            padding: 10px 0 !important;
        }

        .flatpickr-year-select {
            appearance: auto;
            -webkit-appearance: menulist;
            -moz-appearance: menulist;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--primary-dark);
            cursor: pointer;
            min-width: 70px;
            height: auto;
            display: inline-block;
            vertical-align: middle;
        }

        .flatpickr-year-select:hover {
            border-color: var(--primary-purple);
        }

        .flatpickr-year-select:focus {
            outline: none;
            border-color: var(--accent-yellow);
            box-shadow: 0 0 0 2px rgba(251, 186, 58, 0.2);
        }

        .flatpickr-current-month .flatpickr-monthDropdown-months {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
            vertical-align: middle;
        }

        .flatpickr-current-month .flatpickr-monthDropdown-months:hover {
            border-color: var(--primary-purple);
        }

        .flatpickr-current-month .numInputWrapper {
            display: inline-block;
            vertical-align: middle;
        }
    </style>

    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@300;400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/style.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/index.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <div class="hp-portal" lang="en">
        <div class="hp-main">
            <!-- Header Card -->
            <header class="hp-header">
                <div class="hp-logo">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/Hablandis_Kit de marca_Logo alternativo_Color.svg" alt="Hablandis" class="hp-logo-img">
                    <span>Partners</span>
                </div>
                <nav class="hp-nav">
                    <a href="#programs">Programs</a>
                    <a href="#advantages">Advantages</a>
                    <a href="#team">Our Team</a>
                    <a href="#resources">Resources</a>
                    <a href="#faqs">FAQ</a>
                </nav>
                <div class="hp-header-right">
                    <a href="#" class="hp-header-cta" onclick="openEnrollmentModal(event)">ENROLLMENT FORM</a>
                </div>
                <button class="hp-menu-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </header>

            <!-- Mobile Menu Overlay -->
            <div class="hp-mobile-menu-overlay"></div>

            <!-- Mobile Menu -->
            <nav class="hp-mobile-menu">
                <a href="#programs">Programs</a>
                <a href="#advantages">Advantages</a>
                <a href="#team">Our Team</a>
                <a href="#resources">Resources</a>
                <a href="#faqs">FAQ</a>
            </nav>

            <!-- Hero Section Card -->
            <section class="hp-hero">
                <div class="hp-hero-content">
                    <p class="hp-hero-subtitle">BECOME A</p>

                    <!-- Título PARTNER con imagen -->
                    <div class="hp-hero-title-container">
                        <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/logo partner-02.svg" alt="Partner" class="hp-hero-partner-img">
                    </div>

                    <!-- CTA -->
                    <div class="hp-hero-cta-bottom">
                        <a href="#contact" class="hp-hero-cta-top">CONTACT US</a>
                    </div>
                </div>

                <!-- Mosaico de fotos con descripción -->
                <div class="hp-hero-right">
                    <p class="hp-hero-description">
                        From a Beachside School<br>
                        to a Complete Educational Hub
                    </p>
                    <div class="hp-hero-mosaic">
                        <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/header1izq.jpeg" alt="Students" class="hp-mosaic-img">
                        <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/foto header1.jpeg" alt="Teaching" class="hp-mosaic-img">
                        <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/iimagen_rincon.jpg" alt="Learning" class="hp-mosaic-img">
                    </div>
                </div>
            </section>

            <!-- What We've Built Section Card -->
            <section class="hp-what-built">
                <h2>Why Partners Choose Us</h2>
                <div class="hp-infografia-container">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/hablandis agentesv2.png" alt="Why Partners Choose Hablandis" class="hp-infografia-img">
                </div>
            </section>

            <!-- Contact Us CTA 1 -->
            <section class="hp-contact-cta">
                <div class="hp-cta-content">
                    <h2>Ready to Partner With Us?</h2>
                    <p>Join our global network of partners and start growing your business today.</p>
                    <a href="#contact" class="hp-cta-button">Contact Us</a>
                </div>
            </section>

            <!-- Advantages Section Card -->
            <section id="advantages" class="hp-advantages">
            <div class="hp-advantages-header">
                <h2>Our Advantages</h2>
            </div>

            <p class="hp-advantages-desc">
                Hablandis Centro Internacional de Idiomas is a leading language center on the Costa del Sol, Málaga, with over 15 years of experience training teachers and students.
            </p>

            <div class="hp-accordion">
                <div class="hp-accordion-item">
                    <div class="hp-accordion-header" onclick="toggleAccordion(this)">
                        <span class="hp-accordion-number">01.</span>
                        <span class="hp-accordion-title">Competitive Commissions</span>
                        <span class="hp-accordion-icon">+</span>
                    </div>
                    <div class="hp-accordion-content">
                        <div class="hp-accordion-body">
                            <h4>Highly Attractive Commission Structure</h4>
                            <p>We offer highly competitive commissions in the market with timely and transparent payments. Our tiered incentive program rewards your success—the more students you send, the higher your commission rate. Partner with us and maximize your earning potential with one of the most attractive commission structures in the language education industry.</p>
                        </div>
                    </div>
                </div>

                <div class="hp-accordion-item">
                    <div class="hp-accordion-header" onclick="toggleAccordion(this)">
                        <span class="hp-accordion-number">02.</span>
                        <span class="hp-accordion-title">24h Dedicated Support</span>
                        <span class="hp-accordion-icon">+</span>
                    </div>
                    <div class="hp-accordion-content">
                        <div class="hp-accordion-body">
                            <h4>Always By Your Side</h4>
                            <p>Each partner has a dedicated manager who responds within 24 hours. We help you with marketing materials, quotes, and any questions your students may have.</p>
                        </div>
                    </div>
                </div>

                <div class="hp-accordion-item">
                    <div class="hp-accordion-header" onclick="toggleAccordion(this)">
                        <span class="hp-accordion-number">03.</span>
                        <span class="hp-accordion-title">Certified Programs</span>
                        <span class="hp-accordion-icon">+</span>
                    </div>
                    <div class="hp-accordion-content">
                        <div class="hp-accordion-body">
                            <h4>Guaranteed Quality</h4>
                            <p>All our programs are certified by internationally recognized institutions. Trinity CertTESOL, Instituto Cervantes, and more.</p>
                        </div>
                    </div>
                </div>

                <div class="hp-accordion-item">
                    <div class="hp-accordion-header" onclick="toggleAccordion(this)">
                        <span class="hp-accordion-number">04.</span>
                        <span class="hp-accordion-title">Marketing Materials</span>
                        <span class="hp-accordion-icon">+</span>
                    </div>
                    <div class="hp-accordion-content">
                        <div class="hp-accordion-body">
                            <h4>Everything You Need</h4>
                            <p>Access our resource library: brochures, videos, images, and customizable presentations to promote our programs in your market.</p>
                        </div>
                    </div>
                </div>

                <div class="hp-accordion-item">
                    <div class="hp-accordion-header" onclick="toggleAccordion(this)">
                        <span class="hp-accordion-number">05.</span>
                        <span class="hp-accordion-title">End-to-End Partnership Journey</span>
                        <span class="hp-accordion-icon">+</span>
                    </div>
                    <div class="hp-accordion-content">
                        <div class="hp-accordion-body">
                            <h4>Complete Support from A to Z</h4>
                            <p>From initial onboarding to student enrollment and beyond, we guide you through every step. Training, CRM tools, application support, document processing, and visa guidance—all included. You focus on recruiting; we handle the complexity.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Programs Section -->
        <section id="programs" class="hp-programs">
            <h2>Our Programs</h2>

            <div class="hp-programs-grid">
                <div class="hp-program-card">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/eade.jpg" alt="Spanish Preparatory Pathway Program">
                    <div class="hp-program-card-content">
                        <h3>Spanish Preparatory Pathway Program</h3>
                        <p>8-9 month pathway program in Málaga preparing A1 students for B2 level and university admission at EADE University.</p>
                        <a href="#" class="hp-program-cta" onclick="openPathwayModal(event)">Learn More</a>
                    </div>
                </div>

                <div class="hp-program-card">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/curso.jpg" alt="Accredited Spanish Immersion Programs">
                    <div class="hp-program-card-content">
                        <h3>Accredited Spanish Immersion Programs</h3>
                        <p>Flexible Spanish courses for all levels. From intensive programs to summer camps in Málaga's Costa del Sol.</p>
                        <a href="#" class="hp-program-cta" onclick="openImmersionModal(event)">Learn More</a>
                    </div>
                </div>

                <div class="hp-program-card">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/language inmersion.jpeg" alt="Language Immersion Program and Study Trips">
                    <div class="hp-program-card-content">
                        <h3>Language Immersion Program and Study Trips</h3>
                        <p>Combine Spanish language learning with cultural excursions. Perfect for student groups seeking an immersive educational experience in Málaga.</p>
                        <a href="#" class="hp-program-cta" onclick="openStudyTripsModal(event)">Learn More</a>
                    </div>
                </div>

                <div class="hp-program-card">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/teacher.JPG" alt="Accredited Spanish & English Teacher Training">
                    <div class="hp-program-card-content">
                        <h3>Accredited Spanish & English Teacher Training</h3>
                        <p>Professional development for language teachers. Trinity CertTESOL, Advanced ELE training, and Erasmus+ courses with internationally recognized certifications.</p>
                        <a href="#" class="hp-program-cta" onclick="openTeacherTrainingModal(event)">Learn More</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Our Team Section -->
        <section id="team" class="hp-team">
            <h2>Our Team</h2>

            <div class="hp-team-grid">
                <div class="hp-team-card" onclick="openMilaModal(event)">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/mila2.jpeg" alt="Mila Aleksić" class="hp-team-card-img">
                    <div class="hp-team-card-content">
                        <h3>Mila Aleksić</h3>
                        <div class="hp-team-role">Founder & Managing Director</div>
                        <a href="mailto:mila@hablandis.com" class="hp-team-card-btn" onclick="event.stopPropagation();">
                            Contact Mila
                        </a>
                    </div>
                </div>

                <div class="hp-team-card" onclick="openMiriamModal(event)">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/miriamv10.jpeg" alt="Miriam Levie" class="hp-team-card-img">
                    <div class="hp-team-card-content">
                        <h3>Miriam Levie</h3>
                        <div class="hp-team-role">Recruitment & Partnerships Manager</div>
                        <a href="https://calendar.google.com/calendar/u/0/appointments/schedules/AcZssZ3JGkppLj0LAH5or0bYIVx4zNxlLQJrOmzKv8E7wM9HYnriSdMqEvmKCtmLBUbIEKzkB_sWjv6N" target="_blank" class="hp-team-card-btn" onclick="event.stopPropagation();">
                            Schedule a Meeting
                        </a>
                    </div>
                </div>

                <div class="hp-team-card" onclick="openYanaModal(event)">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/yanav8.jpeg" alt="Yana Sharpak" class="hp-team-card-img">
                    <div class="hp-team-card-content">
                        <h3>Yana Sharpak</h3>
                        <div class="hp-team-role">Partnerships Associate</div>
                        <a href="https://calendar.google.com/calendar/appointments/schedules/AcZssZ0Vw1Gs22WXCKxZKS9MlbO831X1hfycXp8NWyxn0OB7jQTUY6rmnas3_y7lmig8Qfdwly_3Sej3" target="_blank" class="hp-team-card-btn" onclick="event.stopPropagation();">
                            Schedule a Meeting
                        </a>
                    </div>
                </div>

                <div class="hp-team-card" onclick="openPiotrModal(event)">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/piotr.jpeg" alt="Piotr Sankowski" class="hp-team-card-img">
                    <div class="hp-team-card-content">
                        <h3>Piotr Sankowski</h3>
                        <div class="hp-team-role">Partnerships Associate</div>
                        <a href="mailto:piotr.sankowski@hablandis.com" class="hp-team-card-btn" onclick="event.stopPropagation();">
                            Contact Piotr
                        </a>
                    </div>
                </div>

                <div class="hp-team-card" onclick="openPeterModal(event)">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/peter.jpeg" alt="Peter Tasker" class="hp-team-card-img">
                    <div class="hp-team-card-content">
                        <h3>Peter Tasker</h3>
                        <div class="hp-team-role">Recruitment & Partnerships Manager | USA</div>
                        <a href="mailto:peter.tasker@hablandis.com" class="hp-team-card-btn" onclick="event.stopPropagation();">
                            Contact Peter
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Resources Section -->
        <section id="resources" class="hp-resources">
            <h2>Partner Resources</h2>
            <p class="hp-resources-subtitle">Download essential documents and resources for your partnership.</p>

            <div class="hp-resources-list">
                <div class="hp-accordion-resource">
                    <div class="hp-accordion-resource-info">
                        <i data-lucide="book-text" style="width:20px;height:20px;color:#afa0c8;"></i>
                        <div>
                            <h4>Agent's Manual 2026</h4>
                            <p>Complete guide for partners with all programs, services and procedures</p>
                        </div>
                    </div>
                    <div class="hp-accordion-resource-meta">
                        <span class="hp-resource-format">PDF</span>
                        <a href="#" class="hp-accordion-download" onclick="downloadResource(event, 'agent-manual')">
                            <i data-lucide="download" style="width:18px;height:18px;"></i>
                        </a>
                    </div>
                </div>

                <div class="hp-accordion-resource">
                    <div class="hp-accordion-resource-info">
                        <i data-lucide="book-open" style="width:20px;height:20px;color:#afa0c8;"></i>
                        <div>
                            <h4>Spanish Courses Price List 2026</h4>
                            <p>Complete pricing and terms & conditions for Spanish courses</p>
                        </div>
                    </div>
                    <div class="hp-accordion-resource-meta">
                        <span class="hp-resource-format">PDF</span>
                        <a href="#" class="hp-accordion-download" onclick="downloadResource(event, 'price-list')">
                            <i data-lucide="download" style="width:18px;height:18px;"></i>
                        </a>
                    </div>
                </div>

                <div class="hp-accordion-resource">
                    <div class="hp-accordion-resource-info">
                        <i data-lucide="route" style="width:20px;height:20px;color:#afa0c8;"></i>
                        <div>
                            <h4>Pathway Program Price List 2026</h4>
                            <p>Complete pricing for university pathway programs</p>
                        </div>
                    </div>
                    <div class="hp-accordion-resource-meta">
                        <span class="hp-resource-format">PDF</span>
                        <a href="#" class="hp-accordion-download" onclick="downloadResource(event, 'pathway-price-list')">
                            <i data-lucide="download" style="width:18px;height:18px;"></i>
                        </a>
                    </div>
                </div>

                <div class="hp-accordion-resource">
                    <div class="hp-accordion-resource-info">
                        <i data-lucide="graduation-cap" style="width:20px;height:20px;color:#afa0c8;"></i>
                        <div>
                            <h4>Teacher Training Courses Price List 2026</h4>
                            <p>Complete pricing for teacher training programs</p>
                        </div>
                    </div>
                    <div class="hp-accordion-resource-meta">
                        <span class="hp-resource-format">PDF</span>
                        <a href="#" class="hp-accordion-download" onclick="downloadResource(event, 'teacher-training-price-list')">
                            <i data-lucide="download" style="width:18px;height:18px;"></i>
                        </a>
                    </div>
                </div>

                <div class="hp-accordion-resource">
                    <div class="hp-accordion-resource-info">
                        <i data-lucide="file-check" style="width:20px;height:20px;color:#afa0c8;"></i>
                        <div>
                            <h4>Application Visa Requirement</h4>
                            <p>Visa requirements and application documentation</p>
                        </div>
                    </div>
                    <div class="hp-accordion-resource-meta">
                        <span class="hp-resource-format">PDF</span>
                        <a href="#" class="hp-accordion-download" onclick="downloadResource(event, 'visa-application')">
                            <i data-lucide="download" style="width:18px;height:18px;"></i>
                        </a>
                    </div>
                </div>

                <div class="hp-accordion-resource">
                    <div class="hp-accordion-resource-info">
                        <i data-lucide="video" style="width:20px;height:20px;color:#afa0c8;"></i>
                        <div>
                            <h4>Promotional Videos</h4>
                            <p>Download promotional clips for Spanish courses, summer camps and more</p>
                        </div>
                    </div>
                    <div class="hp-accordion-resource-meta">
                        <span class="hp-resource-format">VIDEOS</span>
                        <a href="https://drive.google.com/drive/folders/1NSew0ywQgSJzJrk8mspjoMq35jtSZAtM?usp=drive_link" target="_blank" class="hp-accordion-download">
                            <i data-lucide="download" style="width:18px;height:18px;"></i>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact Us CTA 2 -->
        <section class="hp-contact-cta">
            <div class="hp-cta-content">
                <h2>Have Questions?</h2>
                <p>Our team is here to help you get started. Reach out and let's discuss how we can work together.</p>
                <a href="#contact" class="hp-cta-button">Contact Us</a>
            </div>
        </section>

        <!-- Mila Aleksić Modal -->
        <div id="milaModal" class="hp-modal hp-team-modal">
            <div class="hp-modal-overlay" onclick="closeMilaModal()"></div>
            <div class="hp-modal-content">
                <button class="hp-modal-close" onclick="closeMilaModal()">&times;</button>

                <div class="hp-team-modal-header">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/mila2.jpeg" alt="Mila Aleksić" class="hp-team-modal-img">
                    <div class="hp-team-modal-info">
                        <h2>Mila Aleksić</h2>
                        <div class="hp-team-role">Founder & Managing Director</div>

                        <div class="hp-team-contact">
                            <div class="hp-team-contact-item">
                                <i data-lucide="phone" style="width:20px;height:20px;"></i>
                                <a href="tel:+34951936865">+34 951 936 865</a>
                            </div>
                            <div class="hp-team-contact-item">
                                <i data-lucide="mail" style="width:20px;height:20px;"></i>
                                <a href="mailto:mila@hablandis.com">mila@hablandis.com</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hp-team-modal-body">
                    <div class="hp-team-modal-intro">
                        <p>Driving educational excellence through strategic partnerships and a global vision for language immersion.</p>
                    </div>

                    <div class="hp-team-modal-bio">
                        <h3>About Mila</h3>

                        <p>As the founder of Hablandis, my mission is to transform how educational institutions and international agencies manage student mobility in Spain. With a solid track record in directing educational projects, I have designed this B2B ecosystem to provide "plug-and-play" solutions that guarantee both academic quality and seamless logistics.</p>

                        <p>My focus is on building long-term relationships based on trust, ensuring that every partner finds in Hablandis a strategic ally capable of adapting to their specific needs and exceeding their students' expectations.</p>

                        <ul>
                            <li>Strategic vision for the internationalization of educational programs</li>
                            <li>Leadership in creating high-impact language immersion experiences</li>
                            <li>Commitment to innovation and quality in B2B management</li>
                            <li>Expertise in developing international collaboration networks</li>
                        </ul>

                        <p>I am passionate about connecting cultures and opening doors through education, ensuring that every alliance with Hablandis serves as a catalyst for new and successful opportunities for students worldwide.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Miriam Levie Modal -->
        <div id="miriamModal" class="hp-modal hp-team-modal">
            <div class="hp-modal-overlay" onclick="closeMiriamModal()"></div>
            <div class="hp-modal-content">
                <button class="hp-modal-close" onclick="closeMiriamModal()">&times;</button>

                <div class="hp-team-modal-header">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/miriamv10.jpeg" alt="Miriam Levie" class="hp-team-modal-img">
                    <div class="hp-team-modal-info">
                        <h2>Miriam Levie</h2>
                        <div class="hp-team-role">Recruitment & Partnerships Manager</div>

                        <div class="hp-team-contact">
                            <div class="hp-team-contact-item">
                                <i data-lucide="message-circle" style="width:20px;height:20px;"></i>
                                <a href="https://wa.me/34626921640" target="_blank">+34 626 921 640</a>
                            </div>
                            <div class="hp-team-contact-item">
                                <i data-lucide="mail" style="width:20px;height:20px;"></i>
                                <a href="mailto:miriam.levie@hablandis.com">miriam.levie@hablandis.com</a>
                            </div>
                        </div>

                        <a href="https://calendar.google.com/calendar/u/0/appointments/schedules/AcZssZ3JGkppLj0LAH5or0bYIVx4zNxlLQJrOmzKv8E7wM9HYnriSdMqEvmKCtmLBUbIEKzkB_sWjv6N" target="_blank" class="hp-team-header-cta">
                            <i data-lucide="calendar" style="width:18px;height:18px;"></i>
                            Schedule a Meeting
                        </a>
                    </div>
                </div>

                <div class="hp-team-modal-body">
                    <div class="hp-team-modal-intro">
                        <p>As your dedicated connection, I will personally manage your experience to ensure our 'plug-and-play' partnership is a seamless success.</p>
                    </div>

                    <div class="hp-team-modal-bio">
                        <h3>About Miriam</h3>

                        <p>I am a highly qualified linguist, originally from the Netherlands, with over 20 years of international experience in Spain and Italy, where I have founded and led successful TEFL and language schools. I am multilingual and currently work at Hablandis, where my role focuses on developing new international partnerships, teacher training, and supporting international student and teacher mobility programmes.</p>

                        <p>With a strong background in language education and school management, I bring deep linguistic, cultural, and intercultural expertise to everything I do.</p>

                        <ul>
                            <li>Over two decades of experience in the language learning industry</li>
                            <li>Leadership and consultancy in Trinity CertTESOL programmes</li>
                            <li>Extensive experience in founding and managing international language schools</li>
                            <li>Advanced linguistic, cultural, and intercultural communication expertise</li>
                            <li>Practical insight into integration through language and education</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Yana Sharpak Modal -->
        <div id="yanaModal" class="hp-modal hp-team-modal">
            <div class="hp-modal-overlay" onclick="closeYanaModal()"></div>
            <div class="hp-modal-content">
                <button class="hp-modal-close" onclick="closeYanaModal()">&times;</button>

                <div class="hp-team-modal-header">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/yanav8.jpeg" alt="Yana Sharpak" class="hp-team-modal-img">
                    <div class="hp-team-modal-info">
                        <h2>Yana Sharpak</h2>
                        <div class="hp-team-role">Partnerships Associate</div>

                        <div class="hp-team-contact">
                            <div class="hp-team-contact-item">
                                <i data-lucide="message-circle" style="width:20px;height:20px;"></i>
                                <a href="https://wa.me/34951936865" target="_blank">+34 951 936 865</a>
                            </div>
                            <div class="hp-team-contact-item">
                                <i data-lucide="mail" style="width:20px;height:20px;"></i>
                                <a href="mailto:yana.sharpak@hablandis.com">yana.sharpak@hablandis.com</a>
                            </div>
                        </div>

                        <a href="https://calendar.google.com/calendar/appointments/schedules/AcZssZ0Vw1Gs22WXCKxZKS9MlbO831X1hfycXp8NWyxn0OB7jQTUY6rmnas3_y7lmig8Qfdwly_3Sej3" target="_blank" class="hp-team-header-cta">
                            <i data-lucide="calendar" style="width:18px;height:18px;"></i>
                            Schedule a Meeting
                        </a>
                    </div>
                </div>

                <div class="hp-team-modal-body">
                    <div class="hp-team-modal-intro">
                        <p>I'm passionate about international education and creating new opportunities for students worldwide. I collaborate with organizations and networks across different countries to ensure smooth programs, group trips, and pathway courses, helping students make the most of their experience in Spain.</p>
                    </div>

                    <div class="hp-team-modal-bio">
                        <h3>About Yana</h3>

                        <p>With hands-on experience in student relations, I personally support groups during their trips, coordinate activities, and ensure every student feels guided and engaged. Speaking several languages and having an international background allows me to communicate effectively and provide personalized support throughout every collaboration.</p>

                        <ul>
                            <li>Seamless coordination and support for international collaborations</li>
                            <li>Guidance and assistance for student applications and programs</li>
                            <li>Management of group travel, courses, and activities</li>
                            <li>Multilingual communication and cultural insight</li>
                        </ul>

                        <p>I love helping students and collaborators explore new educational opportunities in Spain and making every program inspiring, seamless, and successful.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Piotr Sankowski Modal -->
        <div id="piotrModal" class="hp-modal hp-team-modal">
            <div class="hp-modal-overlay" onclick="closePiotrModal()"></div>
            <div class="hp-modal-content">
                <button class="hp-modal-close" onclick="closePiotrModal()">&times;</button>

                <div class="hp-team-modal-header">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/piotr.jpeg" alt="Piotr Sankowski" class="hp-team-modal-img">
                    <div class="hp-team-modal-info">
                        <h2>Piotr Sankowski</h2>
                        <div class="hp-team-role">Partnerships Associate</div>

                        <div class="hp-team-contact">
                            <div class="hp-team-contact-item">
                                <i data-lucide="message-circle" style="width:20px;height:20px;"></i>
                                <a href="https://wa.me/34626921640" target="_blank">+34 626 921 640</a>
                            </div>
                            <div class="hp-team-contact-item">
                                <i data-lucide="mail" style="width:20px;height:20px;"></i>
                                <a href="mailto:piotr.sankowski@hablandis.com">piotr.sankowski@hablandis.com</a>
                            </div>
                        </div>

                        <a href="mailto:piotr.sankowski@hablandis.com" class="hp-team-header-cta">
                            <i data-lucide="mail" style="width:18px;height:18px;"></i>
                            Contact Piotr
                        </a>
                    </div>
                </div>

                <div class="hp-team-modal-body">
                    <div class="hp-team-modal-intro">
                        <p>Helping teachers and schools create meaningful language immersion experiences in Málaga.</p>
                    </div>

                    <div class="hp-team-modal-bio">
                        <h3>About Piotr</h3>

                        <p>At Hablandis, I support teachers and schools in the planning and organisation of language immersion programmes in Málaga. I guide visiting groups through the entire process, help everything run smoothly, and try to solve problems before anyone even notices they might exist.</p>

                        <p>This may have something to do with the fact that I am also an English and Spanish teacher, with many years of experience working with children and young people. That background helps me understand both the educational value of immersion programmes and the real-life logistics behind organising them – and to effectively communicate what truly makes our programmes meaningful.</p>

                        <p>I genuinely believe in what we do at Hablandis, and encouraging others to take part in an experience you truly trust and understand makes all the difference.</p>

                        <h4>Key responsibilities</h4>
                        <ul>
                            <li>Collaborating with teachers and schools</li>
                            <li>Supporting immersion programme organisation</li>
                            <li>Building educational partnerships</li>
                            <li>Providing marketing support</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Peter Tasker Modal -->
        <div id="peterModal" class="hp-modal hp-team-modal">
            <div class="hp-modal-overlay" onclick="closePeterModal()"></div>
            <div class="hp-modal-content">
                <button class="hp-modal-close" onclick="closePeterModal()">&times;</button>

                <div class="hp-team-modal-header">
                    <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/peter.jpeg" alt="Peter Tasker" class="hp-team-modal-img">
                    <div class="hp-team-modal-info">
                        <h2>Peter Tasker</h2>
                        <div class="hp-team-role">Recruitment & Partnerships Manager | USA</div>

                        <div class="hp-team-contact">
                            <div class="hp-team-contact-item">
                                <i data-lucide="phone" style="width:20px;height:20px;"></i>
                                <a href="tel:+13143984626">+1 (314) 398-4626</a>
                            </div>
                            <div class="hp-team-contact-item">
                                <i data-lucide="mail" style="width:20px;height:20px;"></i>
                                <a href="mailto:peter.tasker@hablandis.com">peter.tasker@hablandis.com</a>
                            </div>
                        </div>

                        <a href="mailto:peter.tasker@hablandis.com" class="hp-team-header-cta">
                            <i data-lucide="mail" style="width:18px;height:18px;"></i>
                            Contact Peter
                        </a>
                    </div>
                </div>

                <div class="hp-team-modal-body">
                    <div class="hp-team-modal-intro">
                        <p>As your U.S. liaison, I'll be here to support you every step of the way and help ensure that you and your students have a deeply enriching and memorable cultural experience.</p>
                    </div>

                    <div class="hp-team-modal-bio">
                        <h3>About Peter</h3>

                        <p>I am a Spanish teacher (and former math teacher!), having taught for 26 years, with an interlude of a dozen years as a toy executive for several US companies. Interlude aside, education is my great passion, and language/cultural immersion is at the core of this passion. I have designed and led student trips to Costa Rica, Guatemala, Panamá, Nicaragua and the Dominican Republic. It is time to focus on Spain, where my time in the Summer of '25 at Hablandis as a student in its TEFL certification program led to a love affair with Málaga, Spanish culture, and the beautiful and talented people of Hablandis.</p>

                        <ul>
                            <li>Recruitment and Partnerships Management USA</li>
                            <li>Ongoing support for school groups and individuals</li>
                            <li>Gran consumidor de vinos y jamones españoles…</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pathway Program Modal -->
        <div id="pathwayModal" class="hp-modal">
            <div class="hp-modal-overlay" onclick="closePathwayModal()"></div>
            <div class="hp-modal-content">
                <button class="hp-modal-close" onclick="closePathwayModal()">&times;</button>

                <div class="hp-modal-header">
                    <h2>Spanish Preparatory Pathway Program</h2>
                    <div class="hp-modal-highlights">
                        <span class="hp-highlight"><i data-lucide="calendar" style="width:16px;height:16px;"></i> Oct-Jan (8-9 months)</span>
                        <span class="hp-highlight"><i data-lucide="map-pin" style="width:16px;height:16px;"></i> Málaga, Spain</span>
                        <span class="hp-highlight">€4,000</span>
                        <span class="hp-highlight"><i data-lucide="book-open" style="width:16px;height:16px;"></i> 640 hours</span>
                    </div>
                </div>

                <div class="hp-modal-tabs">
                    <button class="hp-tab-btn active" onclick="switchTab(event, 'overview')">Overview</button>
                    <button class="hp-tab-btn" onclick="switchTab(event, 'pathways')">Pathway Programs</button>
                    <button class="hp-tab-btn" onclick="switchTab(event, 'why-eade')">Why EADE</button>
                </div>

                <div class="hp-modal-body">
                    <!-- Overview Tab -->
                    <div id="overview" class="hp-tab-content active">
                        <div class="hp-modal-section">
                            <h3>About the Course</h3>
                            <p>Our Spanish Preparatory Course is for A1-level students aiming to reach a B2 level (CEFR). It's part of a Pathway Program that prepares international students for degree studies at EADE University in Málaga and meets Spanish student visa requirements.</p>

                            <p>As an official exam centre, students can take the DELE and SIELE exams on-site. Classes focus on real-life communication and Spanish culture, building strong language and intercultural skills.</p>

                            <p>Students develop all key skills — speaking, listening, reading, and writing — through practical, real-world activities. Grammar and vocabulary are taught in everyday and academic contexts.</p>

                            <p><strong>After completing the course</strong>, students receive a Certificate of Achievement, confirming their B2 level proficiency.</p>
                        </div>

                        <div class="hp-modal-section">
                            <h3>Course Objectives</h3>
                            <ul class="hp-objectives-list">
                                <li>✓ Communicate confidently in academic and everyday settings</li>
                                <li>✓ Use Spanish effectively in speech and writing</li>
                                <li>✓ Understand Spanish culture and social norms</li>
                                <li>✓ Fulfill Spanish student visa requirements</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Pathway Programs Tab -->
                    <div id="pathways" class="hp-tab-content">
                        <div class="hp-modal-section">
                            <h3>Bachelor's Degrees</h3>
                            <div class="hp-pathway-table">
                                <div class="hp-pathway-row hp-pathway-header">
                                    <div class="hp-pathway-col">Program</div>
                                    <div class="hp-pathway-col hp-price">Annual Fee</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">Bachelor's in Business Administration</div>
                                    <div class="hp-pathway-col hp-price">€7,640</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">Bachelor's in Physical Education</div>
                                    <div class="hp-pathway-col hp-price">€8,300</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">Bachelor's in Design (Interior, Graphic, Fashion, Product)</div>
                                    <div class="hp-pathway-col hp-price">€6,160</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">Bachelor's in Video Game Design</div>
                                    <div class="hp-pathway-col hp-price">€7,640</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">Bachelor's in Animation and Visual Effects (VFX)</div>
                                    <div class="hp-pathway-col hp-price">€7,640</div>
                                </div>
                            </div>
                        </div>

                        <div class="hp-modal-section">
                            <h3>Master's Degrees</h3>
                            <div class="hp-pathway-table">
                                <div class="hp-pathway-row hp-pathway-header">
                                    <div class="hp-pathway-col">Program</div>
                                    <div class="hp-pathway-col hp-price">Annual Fee</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">MBA Master's</div>
                                    <div class="hp-pathway-col hp-price">€7,640</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Why EADE Tab -->
                    <div id="why-eade" class="hp-tab-content">
                        <div class="hp-modal-section">
                            <h3>Why Choose EADE University?</h3>
                            <div class="hp-eade-benefits">
                                <div class="hp-benefit-card">
                                    <div class="hp-benefit-icon"><i data-lucide="users" style="width:48px;height:48px;stroke-width:1.5;"></i></div>
                                    <h4>Personalized Attention</h4>
                                    <p>Small class sizes ensure personalized attention to each student</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <div class="hp-benefit-icon"><i data-lucide="lightbulb" style="width:48px;height:48px;stroke-width:1.5;"></i></div>
                                    <h4>Practical Approach</h4>
                                    <p>Real-world application of subjects with hands-on learning</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <div class="hp-benefit-icon"><i data-lucide="globe" style="width:48px;height:48px;stroke-width:1.5;"></i></div>
                                    <h4>International Environment</h4>
                                    <p>Study alongside students from around the world</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <div class="hp-benefit-icon"><i data-lucide="graduation-cap" style="width:48px;height:48px;stroke-width:1.5;"></i></div>
                                    <h4>International Tutorship</h4>
                                    <p>Dedicated support for international students throughout their journey</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hp-modal-footer">
                    <a href="mailto:comunicacion@hablandis.com?subject=Spanish Preparatory Pathway Inquiry" class="hp-modal-cta">Contact Us About This Program</a>
                </div>
            </div>
        </div>

        <!-- Immersion Program Modal -->
        <div id="immersionModal" class="hp-modal">
            <div class="hp-modal-overlay" onclick="closeImmersionModal()"></div>
            <div class="hp-modal-content">
                <button class="hp-modal-close" onclick="closeImmersionModal()">&times;</button>

                <div class="hp-modal-header">
                    <h2>Accredited Spanish Immersion Programs</h2>
                    <div class="hp-modal-highlights">
                        <span class="hp-highlight"><i data-lucide="calendar" style="width:16px;height:16px;"></i> Year-round</span>
                        <span class="hp-highlight"><i data-lucide="map-pin" style="width:16px;height:16px;"></i> Málaga, Spain</span>
                        <span class="hp-highlight">€151-€2,800</span>
                        <span class="hp-highlight"><i data-lucide="users" style="width:16px;height:16px;"></i> Max 8 students</span>
                    </div>
                </div>

                <div class="hp-modal-tabs">
                    <button class="hp-tab-btn active" onclick="switchTab(event, 'immersion-overview')">Overview</button>
                    <button class="hp-tab-btn" onclick="switchTab(event, 'immersion-courses')">Courses & Pricing</button>
                    <button class="hp-tab-btn" onclick="switchTab(event, 'immersion-benefits')">Why Choose Us</button>
                </div>

                <div class="hp-modal-body">
                    <!-- Overview Tab -->
                    <div id="immersion-overview" class="hp-tab-content active">
                        <div class="hp-modal-section">
                            <h3>About the Programs</h3>
                            <p>Our Accredited Spanish Immersion Programs offer flexible learning options for all levels and schedules. From intensive courses to relaxed summer camps, we provide authentic Spanish language experiences in the heart of Málaga's Costa del Sol.</p>

                            <p>All programs feature small groups (maximum 8 students), communicative methodology, and native teachers in a relaxed, multicultural learning environment. Students can choose from various intensity levels to match their goals and availability.</p>

                            <p><strong>After completing any course</strong>, students receive an official certificate recognized internationally, confirming their language proficiency level.</p>
                        </div>

                        <div class="hp-modal-section">
                            <h3>Course Objectives</h3>
                            <ul class="hp-objectives-list">
                                <li>✓ Develop practical Spanish communication skills in real-life contexts</li>
                                <li>✓ Experience Spanish culture and customs through immersive activities</li>
                                <li>✓ Build confidence speaking Spanish in everyday situations</li>
                                <li>✓ Achieve measurable progress according to CEFR levels</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Courses & Pricing Tab -->
                    <div id="immersion-courses" class="hp-tab-content">
                        <div class="hp-modal-section">
                            <h3>Intensive Programs</h3>
                            <div class="hp-pathway-table">
                                <div class="hp-pathway-row hp-pathway-header">
                                    <div class="hp-pathway-col">Course Type</div>
                                    <div class="hp-pathway-col hp-price">Starting Price</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Intensive Spanish Course</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">20 lessons/week • 1 week: €205 | 2 weeks: €410 | 3 weeks: €585 | 4 weeks: €740</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price">From €205/week</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Semi-Intensive Spanish Course</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">10 lessons/week • 1 week: €151 | 2 weeks: €280 | 3 weeks: €390 | 4 weeks: €512</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price">From €151/week</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Combined Spanish Course</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">25 lessons/week (20 group + 5 individual) • 1 week: €405 | 2 weeks: €610 | 3 weeks: €785 | 4 weeks: €940</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price">From €405/week</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Long-term Spanish Course</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">20 lessons/week (from 12 weeks)</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price">€2,800 (12 weeks)</div>
                                </div>
                            </div>
                        </div>

                        <div class="hp-modal-section">
                            <h3>Summer Programs</h3>
                            <div class="hp-pathway-table">
                                <div class="hp-pathway-row hp-pathway-header">
                                    <div class="hp-pathway-col">Course Type</div>
                                    <div class="hp-pathway-col hp-price">Starting Price</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>International Summer Camp</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">1 week full day</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price">€180/week</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Family Summer Spanish Course</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">Intensive + International Summer Camp</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price">€370/week</div>
                                </div>
                            </div>
                            <p class="hp-pathway-note"><em>*All prices include course materials, certificate, and cultural activities. Accommodation available upon request.</em></p>
                        </div>
                    </div>

                    <!-- Why Choose Us Tab -->
                    <div id="immersion-benefits" class="hp-tab-content">
                        <div class="hp-modal-section">
                            <h3>Why Choose Our Programs?</h3>
                            <div class="hp-eade-benefits">
                                <div class="hp-benefit-card">
                                    <div class="hp-benefit-icon"><i data-lucide="users-2" style="width:48px;height:48px;stroke-width:1.5;"></i></div>
                                    <h4>Small Class Sizes</h4>
                                    <p>Maximum 8 students per class for personalized attention and active participation</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <div class="hp-benefit-icon"><i data-lucide="message-circle" style="width:48px;height:48px;stroke-width:1.5;"></i></div>
                                    <h4>Communicative Methodology</h4>
                                    <p>Focus on real-life conversations and practical language skills development</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <div class="hp-benefit-icon"><i data-lucide="user-check" style="width:48px;height:48px;stroke-width:1.5;"></i></div>
                                    <h4>Native Teachers</h4>
                                    <p>Experienced, certified Spanish instructors passionate about teaching</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <div class="hp-benefit-icon"><i data-lucide="palm-tree" style="width:48px;height:48px;stroke-width:1.5;"></i></div>
                                    <h4>Costa del Sol Location</h4>
                                    <p>Study in beautiful Málaga with perfect climate and rich cultural experiences</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hp-modal-footer">
                    <a href="mailto:comunicacion@hablandis.com?subject=Spanish Immersion Programs Inquiry" class="hp-modal-cta">Contact Us About This Program</a>
                </div>
            </div>
        </div>

        <!-- Study Trips Modal -->
        <div id="studyTripsModal" class="hp-modal">
            <div class="hp-modal-overlay" onclick="closeStudyTripsModal()"></div>
            <div class="hp-modal-content">
                <button class="hp-modal-close" onclick="closeStudyTripsModal()">&times;</button>

                <div class="hp-modal-header">
                    <h2>Language Immersion Program and Study Trips</h2>
                    <div class="hp-modal-highlights">
                        <span class="hp-highlight"><i data-lucide="calendar" style="width:16px;height:16px;"></i> 6+ nights</span>
                        <span class="hp-highlight"><i data-lucide="map-pin" style="width:16px;height:16px;"></i> Málaga, Spain</span>
                        <span class="hp-highlight">€460</span>
                        <span class="hp-highlight"><i data-lucide="users" style="width:16px;height:16px;"></i> Group programs</span>
                    </div>
                </div>

                <div class="hp-modal-tabs">
                    <button class="hp-tab-btn active" onclick="switchTab(event, 'trips-overview')">Overview</button>
                    <button class="hp-tab-btn" onclick="switchTab(event, 'trips-pricing')">Pricing</button>
                    <button class="hp-tab-btn" onclick="switchTab(event, 'trips-features')">Key Features</button>
                </div>

                <div class="hp-modal-body">
                    <!-- Overview Tab -->
                    <div id="trips-overview" class="hp-tab-content active">
                        <div class="hp-modal-section">
                            <h3>About the Program</h3>
                            <p>Our Language Immersion Program combines intensive Spanish language instruction with cultural activities and excursions. Designed for student groups, this program offers a complete educational experience with accommodation, meals, and guided cultural exploration in the beautiful city of Málaga.</p>
                        </div>

                        <div class="hp-modal-section">
                            <h3>What's Included</h3>
                            <ul class="hp-objectives-list">
                                <li><i data-lucide="file-text" style="width:20px;height:20px;"></i> Registration, level test, course materials, and final certificate</li>
                                <li><i data-lucide="graduation-cap" style="width:20px;height:20px;"></i> Spanish course: 20 classes of 50 minutes per week</li>
                                <li><i data-lucide="headphones" style="width:20px;height:20px;"></i> 24/7 assistance between school, families, and group leaders</li>
                                <li><i data-lucide="home" style="width:20px;height:20px;"></i> For students: accommodation with host families or hotel. 6 nights, full board, double or triple rooms</li>
                                <li><i data-lucide="user-check" style="width:20px;height:20px;"></i> For leaders: free hotel accommodation, single room, and full board</li>
                                <li><i data-lucide="plane" style="width:20px;height:20px;"></i> Private airport/school transfer (round trip)</li>
                                <li><i data-lucide="map" style="width:20px;height:20px;"></i> Familiarization walk through the tunnels and cliffs of Rincón de la Victoria</li>
                                <li><i data-lucide="gem" style="width:20px;height:20px;"></i> Visit to the Cueva del Tesoro (Treasure Cave)</li>
                                <li><i data-lucide="landmark" style="width:20px;height:20px;"></i> Excursion to Málaga city center with a visit to the Alcazaba and Picasso Museum (half-day)</li>
                                <li><i data-lucide="search" style="width:20px;height:20px;"></i> Scavenger hunt along the promenade</li>
                                <li><i data-lucide="volleyball" style="width:20px;height:20px;"></i> Beach sports</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Pricing Tab -->
                    <div id="trips-pricing" class="hp-tab-content">
                        <div class="hp-modal-section">
                            <h3>Program Pricing</h3>
                            <div class="hp-pathway-table">
                                <div class="hp-pathway-row hp-pathway-header">
                                    <div class="hp-pathway-col">Program Component</div>
                                    <div class="hp-pathway-col hp-price">Details</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">Duration</div>
                                    <div class="hp-pathway-col hp-price">6 nights minimum</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">Spanish Course</div>
                                    <div class="hp-pathway-col hp-price">20 classes × 50 min/week</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">Accommodation</div>
                                    <div class="hp-pathway-col hp-price">Host families or hotel</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">Meals</div>
                                    <div class="hp-pathway-col hp-price">Full board included</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col"><strong>Starting Price</strong></div>
                                    <div class="hp-pathway-col hp-price"><strong>€460 per student</strong></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Key Features Tab -->
                    <div id="trips-features" class="hp-tab-content">
                        <div class="hp-modal-section">
                            <h3>Key Features</h3>
                            <div class="hp-eade-benefits">
                                <div class="hp-benefit-card">
                                    <i data-lucide="users-2" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>For Students</h4>
                                    <p>Accommodation with host families or hotel. 6 nights, full board, double or triple rooms.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="user-check" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>For Group Leaders</h4>
                                    <p>Free hotel accommodation, single room, and full board.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="landmark" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Cultural Excursions</h4>
                                    <p>Familiarization walk through Rincón de la Victoria, Cueva del Tesoro visit, Málaga city center with Alcazaba & Picasso Museum, scavenger hunt along the promenade, and beach sports.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="award" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Complete Spanish Course</h4>
                                    <p>20 classes of 50 minutes per week. Includes registration, level test, course materials, and final certificate.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hp-modal-footer">
                    <a href="mailto:comunicacion@hablandis.com?subject=Language Immersion Program Inquiry" class="hp-modal-cta">Request Group Quote</a>
                </div>
            </div>
        </div>

        <!-- Teacher Training Modal -->
        <div id="teacherTrainingModal" class="hp-modal">
            <div class="hp-modal-overlay" onclick="closeTeacherTrainingModal()"></div>
            <div class="hp-modal-content">
                <button class="hp-modal-close" onclick="closeTeacherTrainingModal()">&times;</button>

                <div class="hp-modal-header">
                    <h2>Accredited Spanish & English Teacher Training</h2>
                    <div class="hp-modal-highlights">
                        <span class="hp-highlight"><i data-lucide="graduation-cap" style="width:16px;height:16px;"></i> Trinity & Cervantes</span>
                        <span class="hp-highlight"><i data-lucide="map-pin" style="width:16px;height:16px;"></i> Málaga, Spain</span>
                        <span class="hp-highlight">€400-€1,699</span>
                        <span class="hp-highlight"><i data-lucide="award" style="width:16px;height:16px;"></i> Internationally recognized</span>
                    </div>
                </div>

                <div class="hp-modal-tabs">
                    <button class="hp-tab-btn active" onclick="switchTab(event, 'training-overview')">Overview</button>
                    <button class="hp-tab-btn" onclick="switchTab(event, 'training-courses')">Courses & Pricing</button>
                    <button class="hp-tab-btn" onclick="switchTab(event, 'training-benefits')">Why Choose Us</button>
                </div>

                <div class="hp-modal-body">
                    <!-- Overview Tab -->
                    <div id="training-overview" class="hp-tab-content active">
                        <div class="hp-modal-section">
                            <h3>About the Program</h3>
                            <p>Professional development programs for language teachers seeking to enhance their teaching skills and methodologies. Our accredited courses combine theoretical knowledge with practical application, delivered by expert ELE and TESOL specialists.</p>
                        </div>

                        <div class="hp-modal-section">
                            <h3>What's Included</h3>
                            <ul class="hp-objectives-list">
                                <li><i data-lucide="book-open" style="width:20px;height:20px;"></i> Complete course materials and resources</li>
                                <li><i data-lucide="user-check" style="width:20px;height:20px;"></i> Expert instruction from certified teacher trainers</li>
                                <li><i data-lucide="users" style="width:20px;height:20px;"></i> Interactive workshops and practical sessions</li>
                                <li><i data-lucide="headphones" style="width:20px;height:20px;"></i> Ongoing support throughout the course</li>
                                <li><i data-lucide="award" style="width:20px;height:20px;"></i> Official certification upon completion</li>
                                <li><i data-lucide="library" style="width:20px;height:20px;"></i> Access to teaching resource library</li>
                                <li><i data-lucide="network" style="width:20px;height:20px;"></i> Networking opportunities with fellow educators</li>
                            </ul>
                        </div>

                        <div class="hp-modal-section">
                            <h3>Key Program Features</h3>
                            <div class="hp-eade-benefits">
                                <div class="hp-benefit-card">
                                    <i data-lucide="users-2" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Small Group Sizes</h4>
                                    <p>Personalized attention with small class sizes for effective learning and individual feedback.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="lightbulb" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Theory & Practice</h4>
                                    <p>Blend of theoretical knowledge and practical teaching experience for real-world application.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="message-circle" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Modern Methodologies</h4>
                                    <p>Focus on communicative approaches and innovative teaching techniques for language education.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="globe" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>International Certification</h4>
                                    <p>Recognized qualifications from Trinity College London and Instituto Cervantes worldwide.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Courses & Pricing Tab -->
                    <div id="training-courses" class="hp-tab-content">
                        <div class="hp-modal-section">
                            <h3>Professional Development Courses</h3>
                            <div class="hp-pathway-table">
                                <div class="hp-pathway-row hp-pathway-header">
                                    <div class="hp-pathway-col">Course Type</div>
                                    <div class="hp-pathway-col hp-price">Price</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Advanced ELE Teacher Training</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">20 sessions on didactic innovation with top ELE specialists</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price"><strong>€400</strong></div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Erasmus+ Courses for Staff</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">2 courses (20 sessions) for language teachers. Offered in Spanish or English</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price"><strong>€400</strong></div>
                                </div>
                            </div>
                        </div>

                        <div class="hp-modal-section">
                            <h3>Trinity CertTESOL - Onsite (Málaga, Spain)</h3>
                            <div class="hp-pathway-table">
                                <div class="hp-pathway-row hp-pathway-header">
                                    <div class="hp-pathway-col">Registration Type</div>
                                    <div class="hp-pathway-col hp-price">Price</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Early Bird</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">If paid 2 months in advance</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price"><strong>€1,499</strong></div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Standard Fee</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">Regular registration</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price"><strong>€1,699</strong></div>
                                </div>
                            </div>
                        </div>

                        <div class="hp-modal-section">
                            <h3>Trinity CertTESOL - Online</h3>
                            <div class="hp-pathway-table">
                                <div class="hp-pathway-row hp-pathway-header">
                                    <div class="hp-pathway-col">Registration Type</div>
                                    <div class="hp-pathway-col hp-price">Price</div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Early Bird</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">If paid 2 months in advance</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price"><strong>€1,299</strong></div>
                                </div>
                                <div class="hp-pathway-row">
                                    <div class="hp-pathway-col">
                                        <div><strong>Standard Fee</strong></div>
                                        <div style="color: #777; font-size: 13px; margin-top: 5px;">Regular registration</div>
                                    </div>
                                    <div class="hp-pathway-col hp-price"><strong>€1,499</strong></div>
                                </div>
                            </div>
                        </div>

                        <div class="hp-modal-section">
                            <h3>Course Details</h3>

                            <h4 style="margin-top: 30px; color: var(--primary-dark);">Advanced ELE Teacher Training (€400)</h4>
                            <ul class="hp-objectives-list">
                                <li><i data-lucide="calendar" style="width:16px;height:16px;"></i> Duration: 20 sessions</li>
                                <li><i data-lucide="target" style="width:16px;height:16px;"></i> Focus: Didactic innovation and modern teaching methods</li>
                                <li><i data-lucide="user-check" style="width:16px;height:16px;"></i> Instructors: Top ELE (Español como Lengua Extranjera) specialists</li>
                                <li><i data-lucide="users" style="width:16px;height:16px;"></i> Format: Interactive workshops and practical sessions</li>
                                <li><i data-lucide="award" style="width:16px;height:16px;"></i> Certification: Course completion certificate</li>
                            </ul>

                            <h4 style="margin-top: 30px; color: var(--primary-dark);">Erasmus+ Courses for Staff (€400)</h4>
                            <ul class="hp-objectives-list">
                                <li><i data-lucide="calendar" style="width:16px;height:16px;"></i> Duration: 2 courses with 20 sessions total</li>
                                <li><i data-lucide="globe" style="width:16px;height:16px;"></i> Languages: Available in Spanish or English</li>
                                <li><i data-lucide="badge-check" style="width:16px;height:16px;"></i> Eligible: Language teachers with Erasmus+ funding</li>
                                <li><i data-lucide="book-open" style="width:16px;height:16px;"></i> Content: Professional development for language educators</li>
                                <li><i data-lucide="award" style="width:16px;height:16px;"></i> Certification: Erasmus+ recognized certificate</li>
                            </ul>

                            <h4 style="margin-top: 30px; color: var(--primary-dark);">Trinity CertTESOL - Onsite</h4>
                            <ul class="hp-objectives-list">
                                <li><i data-lucide="calendar" style="width:16px;height:16px;"></i> Duration: 4 weeks intensive / Part-time options available</li>
                                <li><i data-lucide="map-pin" style="width:16px;height:16px;"></i> Location: Málaga, Spain</li>
                                <li><i data-lucide="euro" style="width:16px;height:16px;"></i> Standard Fee: €1,699 | Early Bird (2 months advance): €1,499</li>
                                <li><i data-lucide="award" style="width:16px;height:16px;"></i> Certification: Trinity College London CertTESOL</li>
                                <li><i data-lucide="presentation" style="width:16px;height:16px;"></i> Teaching Practice: 6 hours with real students</li>
                                <li><i data-lucide="eye" style="width:16px;height:16px;"></i> Observation: 6 hours of experienced teacher classes</li>
                            </ul>

                            <h4 style="margin-top: 30px; color: var(--primary-dark);">Trinity CertTESOL - Online</h4>
                            <ul class="hp-objectives-list">
                                <li><i data-lucide="monitor" style="width:16px;height:16px;"></i> Duration: Flexible online format</li>
                                <li><i data-lucide="euro" style="width:16px;height:16px;"></i> Standard Fee: €1,499 | Early Bird (2 months advance): €1,299</li>
                                <li><i data-lucide="award" style="width:16px;height:16px;"></i> Certification: Trinity College London CertTESOL</li>
                                <li><i data-lucide="presentation" style="width:16px;height:16px;"></i> Teaching Practice: 6 hours via online platform</li>
                                <li><i data-lucide="video" style="width:16px;height:16px;"></i> Interactive: Live sessions with tutors and peers</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Why Choose Us Tab -->
                    <div id="training-benefits" class="hp-tab-content">
                        <div class="hp-modal-section">
                            <h3>Professional Teacher Development Excellence</h3>
                            <p>Our teacher training programs are designed for language educators who want to advance their careers and enhance their teaching methodologies. Whether you're pursuing Trinity CertTESOL, Cervantes Institute accreditation, or Erasmus+ professional development, we provide expert instruction and internationally recognized certifications.</p>
                        </div>

                        <div class="hp-modal-section">
                            <h3>Why Choose Our Programs</h3>
                            <div class="hp-eade-benefits">
                                <div class="hp-benefit-card">
                                    <i data-lucide="award" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Internationally Recognized Certifications</h4>
                                    <p>Trinity College London accredited center for CertTESOL. Cervantes Institute approved for ELE teacher training. Erasmus+ accredited institution for staff mobility. Certificates accepted by language schools worldwide.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="user-check" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Expert Instruction</h4>
                                    <p>Highly qualified trainers with extensive teaching experience. Specialists in both ELE (Spanish) and TESOL (English) methodologies. Certified by Trinity College London and Instituto Cervantes. Small class sizes for personalized attention.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="target" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Practical & Theory Balance</h4>
                                    <p>Hands-on teaching practice with real students. Observation of experienced teachers in action. Immediate feedback and personalized coaching sessions. Modern, communicative teaching methodologies.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="settings" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Flexible Learning Options</h4>
                                    <p>Onsite intensive courses in Málaga. Online courses for remote learners worldwide. Part-time and full-time formats available. Early bird discounts for advance payment. Erasmus+ funding accepted.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="headphones" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Comprehensive Support</h4>
                                    <p>Complete course materials and teaching resources. Access to extensive digital resource library. Post-course job placement assistance. Alumni network for ongoing professional development.</p>
                                </div>
                                <div class="hp-benefit-card">
                                    <i data-lucide="map-pin" style="width:40px;height:40px;color:var(--primary-dark);"></i>
                                    <h4>Located in Beautiful Málaga, Spain</h4>
                                    <p>Study in a vibrant, historic Spanish city. Perfect climate year-round for learning. Immerse yourself in Spanish culture and language. Safe, welcoming environment for international students.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hp-modal-footer">
                    <a href="mailto:comunicacion@hablandis.com?subject=Teacher Training Program Inquiry" class="hp-modal-cta">Request Information</a>
                </div>
            </div>
        </div>

        <!-- FAQs Section -->
        <section id="faqs" class="hp-faqs">
            <h2>Frequently Asked Questions</h2>

            <div class="hp-faqs-container">
                <!-- FAQ 1 -->
                <div class="hp-faq-item">
                    <button class="hp-faq-question" onclick="toggleFAQ(event, 'faq1')">
                        <span class="hp-faq-question-text">What are the requirements to become a Hablandis partner?</span>
                        <div class="hp-faq-icon">
                            <i data-lucide="chevron-down" style="width:18px;height:18px;"></i>
                        </div>
                    </button>
                    <div class="hp-faq-answer" id="faq1">
                        <div class="hp-faq-answer-content">
                            <p>To become a Hablandis partner, you should:</p>
                            <ul>
                                <li>Be an accredited educational institution, language school, or educational agency</li>
                                <li>Have experience working with international students</li>
                                <li>Demonstrate commitment to quality education and student welfare</li>
                                <li>Be able to provide ongoing support to students throughout their program</li>
                            </ul>
                            <p>We welcome partners from all countries and work with universities, high schools, language centers, and educational agencies worldwide.</p>
                        </div>
                    </div>
                </div>

                <!-- FAQ 2 -->
                <div class="hp-faq-item">
                    <button class="hp-faq-question" onclick="toggleFAQ(event, 'faq2')">
                        <span class="hp-faq-question-text">How does the commission structure work?</span>
                        <div class="hp-faq-icon">
                            <i data-lucide="chevron-down" style="width:18px;height:18px;"></i>
                        </div>
                    </button>
                    <div class="hp-faq-answer" id="faq2">
                        <div class="hp-faq-answer-content">
                            <p>Our commission structure is transparent and competitive:</p>
                            <ul>
                                <li>Higher volume partners receive enhanced commission rates</li>
                                <li>Payments are processed monthly via bank transfer</li>
                                <li>No hidden fees or charges</li>
                                <li>Detailed reporting available through your partner portal</li>
                            </ul>
                            <p>Contact us for specific commission details for your partnership level.</p>
                        </div>
                    </div>
                </div>

                <!-- FAQ 3 -->
                <div class="hp-faq-item">
                    <button class="hp-faq-question" onclick="toggleFAQ(event, 'faq3')">
                        <span class="hp-faq-question-text">What marketing support do you provide to partners?</span>
                        <div class="hp-faq-icon">
                            <i data-lucide="chevron-down" style="width:18px;height:18px;"></i>
                        </div>
                    </button>
                    <div class="hp-faq-answer" id="faq3">
                        <div class="hp-faq-answer-content">
                            <p>We provide comprehensive marketing support including:</p>
                            <ul>
                                <li>Complete marketing kit with logos, brochures, and promotional materials</li>
                                <li>Social media templates and content calendars</li>
                                <li>Professional photos and videos of our facilities and programs</li>
                                <li>Customizable presentations for student recruitment</li>
                                <li>SEO-optimized web content and banners</li>
                                <li>Ongoing training on effective marketing strategies</li>
                            </ul>
                            <p>All materials are available in multiple languages and regularly updated.</p>
                        </div>
                    </div>
                </div>

                <!-- FAQ 4 -->
                <div class="hp-faq-item">
                    <button class="hp-faq-question" onclick="toggleFAQ(event, 'faq4')">
                        <span class="hp-faq-question-text">How long does the application process take?</span>
                        <div class="hp-faq-icon">
                            <i data-lucide="chevron-down" style="width:18px;height:18px;"></i>
                        </div>
                    </button>
                    <div class="hp-faq-answer" id="faq4">
                        <div class="hp-faq-answer-content">
                            <p>The partnership application process typically takes 2-3 weeks:</p>
                            <ul>
                                <li><strong>Week 1:</strong> Initial application review and introductory call with our partnerships team</li>
                                <li><strong>Week 2:</strong> Due diligence, contract negotiation, and partnership agreement finalization</li>
                                <li><strong>Week 3:</strong> Onboarding, training, and access to partner resources</li>
                            </ul>
                            <p>Once approved, you can start promoting programs and enrolling students immediately.</p>
                        </div>
                    </div>
                </div>

                <!-- FAQ 5 -->
                <div class="hp-faq-item">
                    <button class="hp-faq-question" onclick="toggleFAQ(event, 'faq5')">
                        <span class="hp-faq-question-text">What kind of training and support do partners receive?</span>
                        <div class="hp-faq-icon">
                            <i data-lucide="chevron-down" style="width:18px;height:18px;"></i>
                        </div>
                    </button>
                    <div class="hp-faq-answer" id="faq5">
                        <div class="hp-faq-answer-content">
                            <p>Partners receive comprehensive ongoing support:</p>
                            <ul>
                                <li>Initial onboarding training covering all programs, enrollment processes, and systems</li>
                                <li>Dedicated partnership manager for personalized support</li>
                                <li>Regular webinars and training sessions on new programs and updates</li>
                                <li>24/7 access to partner resource center with guides and FAQs</li>
                                <li>WhatsApp support group for quick questions and updates</li>
                                <li>Annual partner conference in Málaga for networking and training</li>
                            </ul>
                            <p>We're committed to your success and provide support every step of the way.</p>
                        </div>
                    </div>
                </div>

                <!-- FAQ 6 -->
                <div class="hp-faq-item">
                    <button class="hp-faq-question" onclick="toggleFAQ(event, 'faq6')">
                        <span class="hp-faq-question-text">Can I visit the Hablandis facilities in Málaga?</span>
                        <div class="hp-faq-icon">
                            <i data-lucide="chevron-down" style="width:18px;height:18px;"></i>
                        </div>
                    </button>
                    <div class="hp-faq-answer" id="faq6">
                        <div class="hp-faq-answer-content">
                            <p>Absolutely! We encourage all potential and current partners to visit our facilities:</p>
                            <ul>
                                <li>Schedule a personalized campus tour and meet our team</li>
                                <li>Observe classes and experience our teaching methodology firsthand</li>
                                <li>Meet current students and hear about their experiences</li>
                                <li>Explore Málaga and understand why it's the perfect location for language learning</li>
                                <li>Participate in our annual partner conference (typically held in June)</li>
                            </ul>
                            <p>Contact us to arrange your visit - we'd love to show you around!</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Accreditations Section -->
        <section class="hp-accreditations">
            <h2>Our Accreditations</h2>
            <div class="hp-accreditations-img-container">
                <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/Acreditaciones5.png" alt="Hablandis Accreditations" class="hp-accreditations-img">
            </div>
        </section>

        <!-- Contact Section -->
        <section id="contact" class="hp-cta-section hp-contact-section">
            <h2>Get in <span>Touch</span></h2>
            <p class="hp-contact-intro">Got any doubts or questions? We are here for you!</p>

            <form class="hp-contact-form-grid" id="contactForm">
                <div class="hp-contact-form-row">
                    <div class="hp-contact-input-group">
                        <label for="firstName">First name *</label>
                        <input type="text" id="firstName" name="firstName" required>
                    </div>
                    <div class="hp-contact-input-group">
                        <label for="lastName">Last name *</label>
                        <input type="text" id="lastName" name="lastName" required>
                    </div>
                </div>

                <div class="hp-contact-form-row">
                    <div class="hp-contact-input-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="hp-contact-input-group">
                        <label for="company">Company name *</label>
                        <input type="text" id="company" name="company" required>
                    </div>
                </div>

                <div class="hp-contact-input-group hp-contact-full-width">
                    <label for="message">Comments or questions</label>
                    <textarea id="message" name="message" rows="5"></textarea>
                </div>

                <button type="submit" class="hp-cta-button hp-contact-submit">Submit</button>
            </form>

            <div id="enrollment-form" class="hp-enrollment-section">
                <p><strong>Need to send student enrollment data?</strong><br>
                Use our dedicated Enrollment Form to submit student information, course preferences, and all necessary documentation.</p>
                <a href="#" class="hp-enrollment-button" onclick="openEnrollmentModal(event)">Go to Enrollment Form</a>
            </div>

            <div class="hp-contact-methods-section">
                <p class="hp-contact-methods-intro">Please feel free to reach out to the most convenient communication platform found below:</p>

                <div class="hp-contact-method-buttons">
                    <a href="mailto:comunicacion@hablandis.com" class="hp-contact-method-link">
                        <i data-lucide="mail" style="width:20px;height:20px;"></i>
                        <span>Send us an email</span>
                    </a>
                    <a href="https://wa.me/34951936865" target="_blank" class="hp-contact-method-link">
                        <i data-lucide="message-circle" style="width:20px;height:20px;"></i>
                        <span>Send us a WhatsApp</span>
                    </a>
                    <a href="tel:+34951936865" class="hp-contact-method-link">
                        <i data-lucide="phone" style="width:20px;height:20px;"></i>
                        <span>Call us (Spain)</span>
                    </a>
                </div>

                <p class="hp-contact-info">
                    <strong>Email:</strong> <a href="mailto:comunicacion@hablandis.com">comunicacion@hablandis.com</a><br>
                    <strong>Phone:</strong> <a href="tel:+34951936865">+34 951 936 865</a>
                </p>
            </div>
        </section>

        <!-- Footer -->
        <footer class="hp-footer">
            <div class="hp-footer-content">
                <div class="hp-footer-brand">
                    <div class="hp-footer-brand-header">
                        <img src="<?php echo get_stylesheet_directory_uri(); ?>/images/Hablandis_Kit de marca_Logo alternativo_Color.svg" alt="Hablandis Partners" class="hp-footer-logo">
                        <span>Partners</span>
                    </div>
                    <p>Born in 2011, we grew from a Spanish school in year-round Málaga into a comprehensive hub. We pair personal care with a 'plug-and-play' process for partners. Our expertise covers: Spanish immersion, English programs, accredited training for ELE (Spanish) and TEFL (English), and University Pathway Program — your gateway to Spanish higher education with EADE Business School. As an official exam center for Cervantes, Trinity, and Cambridge, we are the efficient, all-in-one partner.</p>
                </div>

                <div class="hp-footer-links">
                    <h4>Spanish Courses</h4>
                    <ul>
                        <li><a href="https://www.hablandis.com/cursos-espanol/curso-intensivo/" target="_blank">Intensive Course</a></li>
                        <li><a href="https://www.hablandis.com/cursos-espanol/curso-semi-intensivo/" target="_blank">Semi-Intensive Course</a></li>
                        <li><a href="https://www.hablandis.com/cursos-espanol/curso-combinado/" target="_blank">Combined Course</a></li>
                        <li><a href="https://www.hablandis.com/cursos-espanol/curso-familiar/" target="_blank">Family Course</a></li>
                        <li><a href="https://www.hablandis.com/cursos-espanol/bildungsurlaub/" target="_blank">Bildungsurlaub Course</a></li>
                    </ul>
                </div>

                <div class="hp-footer-links">
                    <h4>Summer Camps</h4>
                    <ul>
                        <li><a href="https://www.hablandis.com/campamentos/campamento-de-verano-internacional/" target="_blank">International Summer Camp</a></li>
                        <li><a href="https://www.hablandis.com/campamentos/campamento-de-verano-familiar/" target="_blank">Family Summer Camp</a></li>
                    </ul>
                </div>

                <div class="hp-footer-links">
                    <h4>Contact</h4>
                    <ul>
                        <li><a href="https://www.hablandis.com" target="_blank">www.hablandis.com</a></li>
                        <li><a href="mailto:comunicacion@hablandis.com">comunicacion@hablandis.com</a></li>
                        <li><a href="tel:+34951936865">+34 951 936 865</a></li>
                        <li>Paseo de las Palmeras, 1</li>
                        <li>29730 Rincón de la Victoria</li>
                        <li>Málaga, Spain</li>
                        <li>Tax ID: B-93107886</li>
                    </ul>
                </div>
            </div>

            <div class="hp-footer-bottom">
                © <?php echo date('Y'); ?> Hablandis International Language Center. All rights reserved.
            </div>
            </footer>

        <!-- Enrollment Form Modal -->
        <div id="enrollmentModal" class="hp-enrollment-modal" lang="en">
            <div class="hp-enrollment-modal-content">
                <div class="hp-enrollment-modal-header">
                    <button class="hp-enrollment-close" onclick="closeEnrollmentModal()">&times;</button>
                    <h2 class="hp-enrollment-modal-title">Enrollment Form</h2>

                    <!-- Progress Bar -->
                    <div class="hp-enrollment-progress">
                        <div class="hp-enrollment-step active" data-step="1">
                            <div class="hp-enrollment-step-circle"></div>
                            <div class="hp-enrollment-step-label">Course</div>
                        </div>
                        <div class="hp-enrollment-step" data-step="2">
                            <div class="hp-enrollment-step-circle"></div>
                            <div class="hp-enrollment-step-label">Housing</div>
                        </div>
                        <div class="hp-enrollment-step" data-step="3">
                            <div class="hp-enrollment-step-circle"></div>
                            <div class="hp-enrollment-step-label">User Details</div>
                        </div>
                        <div class="hp-enrollment-step" data-step="4">
                            <div class="hp-enrollment-step-circle"></div>
                            <div class="hp-enrollment-step-label">Visa</div>
                        </div>
                        <div class="hp-enrollment-step" data-step="5">
                            <div class="hp-enrollment-step-circle"></div>
                            <div class="hp-enrollment-step-label">Insurance</div>
                        </div>
                        <div class="hp-enrollment-step" data-step="6">
                            <div class="hp-enrollment-step-circle"></div>
                            <div class="hp-enrollment-step-label">Agency Information</div>
                        </div>
                        <div class="hp-enrollment-step" data-step="7">
                            <div class="hp-enrollment-step-circle"></div>
                            <div class="hp-enrollment-step-label">Summary</div>
                        </div>
                    </div>
                </div>

                <form id="enrollmentForm">
                    <div class="hp-enrollment-modal-body">
                        <!-- Step 1: Course -->
                        <div class="hp-enrollment-form-step active" data-step="1">
                            <h3 style="margin-bottom: 25px; color: var(--primary-dark);">Course Information</h3>

                            <div class="hp-enrollment-form-group">
                                <label>School *</label>
                                <select name="school" required>
                                    <option value="">Select a school</option>
                                    <option value="hablandis-beach-rincon">Hablandis-Beach-Rincón de la Victoria</option>
                                    <option value="hablandis-mountain-colmenar">Hablandis-Mountain-Colmenar</option>
                                    <option value="hablandis-mountain-casabermeja">Hablandis-Mountain-Casabermeja</option>
                                    <option value="hablandis-mountain-riogordo">Hablandis-Mountain-Riogordo</option>
                                </select>
                            </div>

                            <div class="hp-enrollment-form-group">
                                <label>Course Category *</label>
                                <input type="hidden" name="courseType" id="courseTypeInput" required>
                                <input type="hidden" name="courseCategory" id="courseCategoryInput" required>

                                <!-- Main Category Selector -->
                                <div class="hp-course-options hp-category-selector" id="mainCategorySelector">
                                    <div class="hp-course-option hp-category-option" data-category="pathway">University Pathway Program</div>
                                    <div class="hp-course-option hp-category-option" data-category="long-term">Long-term Courses</div>
                                    <div class="hp-course-option hp-category-option" data-category="short-term">Short-term Courses</div>
                                    <div class="hp-course-option hp-category-option" data-category="summer">Summer Programs</div>
                                    <div class="hp-course-option hp-category-option" data-category="teacher">Teacher Training</div>
                                    <div class="hp-course-option hp-category-option" data-category="immersion">Language Immersion Program</div>
                                </div>

                                <!-- University Pathway Program Details (Hidden by default) -->
                                <div class="hp-course-details" id="pathwayDetails" style="display: none;">
                                    <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                                        <label>Program Type *</label>
                                        <select name="pathwayProgram" id="pathwayProgramSelect" class="hp-course-select">
                                            <option value="">Select program</option>
                                            <option value="spanish-bachelors">Spanish + Bachelor's (total 4 years)</option>
                                            <option value="spanish-mba">Spanish + MBA</option>
                                        </select>
                                    </div>
                                    <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                                        <label>Preferred Start Date *</label>
                                        <input type="text" name="pathwayStartDate" id="pathwayStartDateInput" placeholder="Select date" class="hp-course-select">
                                    </div>
                                </div>

                                <!-- Long-term Courses Details (Hidden by default) -->
                                <div class="hp-course-details" id="longTermDetails" style="display: none;">
                                    <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                                        <label>Course Duration *</label>
                                        <select name="longTermDuration" id="longTermDurationSelect" class="hp-course-select">
                                            <option value="">Select duration</option>
                                            <option value="12-weeks">12 weeks</option>
                                            <option value="24-weeks">24 weeks</option>
                                            <option value="36-weeks">36 weeks</option>
                                            <option value="48-weeks">48 weeks</option>
                                        </select>
                                    </div>
                                    <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                                        <label>Course Start Date (Monday) *</label>
                                        <input type="date" name="startDate" id="startDateInput" class="monday-only-date" lang="en">
                                    </div>
                                </div>

                                <!-- Short-term Courses Details (Hidden by default) -->
                                <div class="hp-course-details" id="shortTermDetails" style="display: none;">
                                    <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                                        <label>Select Course *</label>
                                        <select name="shortTermCourse" id="shortTermCourseSelect" class="hp-course-select">
                                            <option value="">Select a course</option>
                                            <option value="intensive">Intensive Spanish Course (20 lessons/week)</option>
                                            <option value="semi-intensive">Semi-intensive Spanish Course (10 lessons/week)</option>
                                            <option value="combined-20-5">Combined Spanish Course (25 lessons/week: 20 group + 5 private)</option>
                                            <option value="combined-20-10">Combined Spanish Course (30 lessons/week: 20 group + 10 private)</option>
                                            <option value="dele">DELE Exam Preparation Course (20 lessons/week - Group classes if group is formed)</option>
                                            <option value="private-1">Private Spanish Course (1 Student)</option>
                                            <option value="private-2">Private Spanish Course (2 Students)</option>
                                        </select>
                                    </div>
                                    <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                                        <label>Number of Weeks *</label>
                                        <input type="number" name="weeks" min="1" max="11">
                                    </div>
                                    <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                                        <label>Course Start Date (Monday) *</label>
                                        <input type="date" name="shortTermStartDate" id="shortTermStartDateInput" class="monday-only-date" lang="en">
                                    </div>
                                </div>

                                <!-- Summer Programs Details (Hidden by default) -->
                                <div class="hp-course-details" id="summerDetails" style="display: none;">
                                    <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                                        <label>Select Program *</label>
                                        <select name="summerCourse" id="summerCourseSelect" class="hp-course-select">
                                            <option value="">Select a program</option>
                                            <option value="summer-family-day">Summer Family Course - Day Camp</option>
                                            <option value="international-day">International Summer Camp - Day Camp</option>
                                            <option value="international-full">International Summer Camp - Full Day Camp</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Teacher Training Details (Hidden by default) -->
                                <div class="hp-course-details" id="teacherDetails" style="display: none;">
                                    <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                                        <label>Select Course *</label>
                                        <select name="teacherCourse" id="teacherCourseSelect" class="hp-course-select">
                                            <option value="">Select a course</option>
                                            <option value="teacher-advanced-ele">Advanced ELE Teacher Training (1 week - 20 sessions on didactic innovation with top ELE specialists)</option>
                                            <option value="teacher-erasmus-staff">Erasmus+ Courses for Staff (7 courses for language teachers, offered in Spanish or English)</option>
                                            <option value="teacher-trinity-onsite-earlybird">Onsite Trinity CertTESOL Course (4 weeks) - Early Bird (paid 2 months in advance)</option>
                                            <option value="teacher-trinity-onsite-standard">Onsite Trinity CertTESOL Course (4 weeks) - Standard course fee</option>
                                            <option value="teacher-trinity-online-earlybird">Online Trinity CertTESOL Course (10 weeks) - Early Bird (paid 2 months in advance)</option>
                                            <option value="teacher-trinity-online-standard">Online Trinity CertTESOL Course (10 weeks) - Standard course fee</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Language Immersion Details (Hidden by default) -->
                                <div class="hp-course-details" id="immersionDetails" style="display: none;">
                                    <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                                        <label>Select Program *</label>
                                        <select name="immersionCourse" id="immersionCourseSelect" class="hp-course-select">
                                            <option value="">Select a program</option>
                                            <option value="study-trips-homestay">Student groups from 5 nights with homestay</option>
                                        </select>
                                    </div>
                                    <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                                        <label>Number of Nights *</label>
                                        <input type="number" name="immersionNights" min="5">
                                    </div>
                                    <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                                        <label>Course Start Date *</label>
                                        <input type="date" name="studentGroupStartDate" id="studentGroupStartDateInput" class="any-day-date" lang="en">
                                    </div>
                                </div>
                            </div>

                            <div id="coursesContainer">
                                <!-- Additional courses will be added here -->
                            </div>

                            <button type="button" class="hp-add-course-btn" onclick="addCourse()">
                                <span style="font-size: 18px; margin-right: 5px;">+</span> Add Another Course
                            </button>

                            <div class="hp-enrollment-form-group" style="margin-top: 30px;">
                                <label>Anything else we should take into account while assigning your class? (accessibility, preference, etc) *</label>
                                <textarea name="classNotes" rows="4" placeholder="Please share any special requirements, preferences, or considerations we should be aware of..." required></textarea>
                            </div>
                        </div>

                        <!-- Step 2: Housing -->
                        <div class="hp-enrollment-form-step" data-step="2">
                            <h3 style="margin-bottom: 25px; color: var(--primary-dark);">Housing Information</h3>

                            <div class="hp-enrollment-form-group">
                                <label>Housing Required *</label>
                                <input type="hidden" name="housingRequired" id="housingRequiredInput" required>
                                <div class="hp-housing-options">
                                    <div class="hp-housing-option" data-value="yes">Yes</div>
                                    <div class="hp-housing-option" data-value="no">No</div>
                                </div>
                            </div>

                            <div id="housingDetailsContainer" style="display: none;">
                                <div class="hp-enrollment-form-group">
                                    <label>Accommodation Type *</label>
                                    <select name="accommodationType" id="accommodationTypeInput" required>
                                        <option value="">Select accommodation type</option>
                                        <option value="homestay">Spanish Family (Homestay)</option>
                                        <option value="student-apartment">Shared Apartment</option>
                                        <option value="private-apartment">Private Apartment</option>
                                    </select>
                                </div>

                                <!-- Room Options (Conditional based on accommodation type) -->
                                <div id="roomOptionsContainer" class="hp-enrollment-form-group" style="display: none;">
                                    <label>Room & Board Options *</label>
                                    <select name="housingType" id="housingTypeInput">
                                        <option value="">Select room option</option>
                                    </select>
                                </div>

                                <!-- Double Room Occupancy Type (Conditional) -->
                                <div id="doubleRoomOccupancyContainer" class="hp-enrollment-form-group" style="display: none;">
                                    <label>Double Room Occupancy *</label>
                                    <select name="doubleRoomOccupancy" id="doubleRoomOccupancyInput">
                                        <option value="">Select occupancy type</option>
                                        <option value="two-students">Two students traveling together</option>
                                        <option value="family-companion">Non-enrolled companion</option>
                                    </select>
                                </div>

                                <div id="housingDatesContainer" style="display: none;">
                                    <div class="hp-enrollment-form-row">
                                        <div class="hp-enrollment-form-group">
                                            <label>Check-in Date *</label>
                                            <input type="date" name="checkInDate" id="checkInDateInput" lang="en">
                                        </div>
                                        <div class="hp-enrollment-form-group">
                                            <label>Check-out Date *</label>
                                            <input type="date" name="checkOutDate" id="checkOutDateInput" lang="en">
                                        </div>
                                    </div>
                                </div>

                                <div class="hp-enrollment-form-group">
                                    <label>Special Requirements</label>
                                    <textarea name="housingRequirements" rows="4" placeholder="Dietary restrictions, allergies, pets, etc."></textarea>
                                </div>

                                <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                                    <label>Airport Transfer Required *</label>
                                    <input type="hidden" name="transferRequired" id="transferRequiredInput">
                                    <div class="hp-housing-options">
                                        <div class="hp-housing-option hp-transfer-option" data-value="yes">Yes</div>
                                        <div class="hp-housing-option hp-transfer-option" data-value="no">No</div>
                                    </div>
                                </div>

                                <div id="transferDetailsContainer" style="display: none;">
                                    <div class="hp-enrollment-form-group">
                                        <label>Transfer Type *</label>
                                        <select name="transferType" id="transferTypeInput">
                                            <option value="">Select transfer type</option>
                                            <option value="arrival">Arrival only</option>
                                            <option value="departure">Departure only</option>
                                            <option value="both">Both (Arrival & Departure)</option>
                                        </select>
                                    </div>

                                    <div id="arrivalTransferContainer" style="display: none;">
                                        <div class="hp-enrollment-form-group">
                                            <label>Arrival Date *</label>
                                            <input type="date" name="arrivalTransferDate" id="arrivalTransferDateInput" lang="en">
                                        </div>
                                    </div>

                                    <div id="departureTransferContainer" style="display: none;">
                                        <div class="hp-enrollment-form-group">
                                            <label>Departure Date *</label>
                                            <input type="date" name="departureTransferDate" id="departureTransferDateInput" lang="en">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: User Details (Student, Teacher) -->
                        <div class="hp-enrollment-form-step" data-step="3">
                            <h3 style="margin-bottom: 25px; color: var(--primary-dark);">User Details (Student, Teacher)</h3>

                            <div class="hp-enrollment-form-row">
                                <div class="hp-enrollment-form-group">
                                    <label>First Name *</label>
                                    <input type="text" name="studentFirstName" required>
                                </div>
                                <div class="hp-enrollment-form-group">
                                    <label>Last Name *</label>
                                    <input type="text" name="studentLastName" required>
                                </div>
                            </div>

                            <div class="hp-enrollment-form-row">
                                <div class="hp-enrollment-form-group">
                                    <label>Sex as it appears on passport *</label>
                                    <select name="studentSex" required>
                                        <option value="">Please select</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="diverse">Diverse</option>
                                    </select>
                                </div>
                                <div class="hp-enrollment-form-group">
                                    <label>Birthdate *</label>
                                    <input type="text" name="studentDOB" id="studentDOBInput" placeholder="Select date" required>
                                </div>
                            </div>

                            <div class="hp-enrollment-form-group">
                                <label>If the student is a MINOR please attach Parental Consent Form</label>
                                <small style="color: #666; display: block; margin-bottom: 8px;">You can find the form in our Partner Portal</small>
                                <div class="hp-file-upload-container" id="parentalConsentContainer">
                                    <input type="file" name="parentalConsentForm" id="parentalConsentInput" accept=".pdf,.doc,.docx,image/*" style="display: none;">
                                    <div class="hp-file-upload-box" id="parentalConsentBox" onclick="document.getElementById('parentalConsentInput').click()">
                                        <i data-lucide="upload" style="width: 30px; height: 30px; color: #9575cd; margin-bottom: 8px;"></i>
                                        <span>Click to upload consent form</span>
                                        <small style="color: #999;">PDF, DOC or image</small>
                                    </div>
                                    <div class="hp-file-preview" id="parentalConsentPreview" style="display: none;">
                                        <div class="hp-file-pdf-icon" id="parentalConsentIcon">DOC</div>
                                        <div class="hp-file-preview-info">
                                            <span id="parentalConsentName"></span>
                                            <button type="button" class="hp-file-remove-btn" onclick="removeConsentFile()">&times;</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="hp-enrollment-form-row">
                                <div class="hp-enrollment-form-group">
                                    <label>Nationality *</label>
                                    <select name="studentNationality" id="studentNationalitySelect" required>
                                        <option value="">Please select</option>
                                        <option value="afghan">Afghan</option>
                                        <option value="albanian">Albanian</option>
                                        <option value="algerian">Algerian</option>
                                        <option value="american">American</option>
                                        <option value="andorran">Andorran</option>
                                        <option value="angolan">Angolan</option>
                                        <option value="argentine">Argentine</option>
                                        <option value="armenian">Armenian</option>
                                        <option value="australian">Australian</option>
                                        <option value="austrian">Austrian</option>
                                        <option value="azerbaijani">Azerbaijani</option>
                                        <option value="bahraini">Bahraini</option>
                                        <option value="bangladeshi">Bangladeshi</option>
                                        <option value="belarusian">Belarusian</option>
                                        <option value="belgian">Belgian</option>
                                        <option value="bolivian">Bolivian</option>
                                        <option value="bosnian">Bosnian</option>
                                        <option value="brazilian">Brazilian</option>
                                        <option value="british">British</option>
                                        <option value="bulgarian">Bulgarian</option>
                                        <option value="cambodian">Cambodian</option>
                                        <option value="cameroonian">Cameroonian</option>
                                        <option value="canadian">Canadian</option>
                                        <option value="chilean">Chilean</option>
                                        <option value="chinese">Chinese</option>
                                        <option value="colombian">Colombian</option>
                                        <option value="costa-rican">Costa Rican</option>
                                        <option value="croatian">Croatian</option>
                                        <option value="cuban">Cuban</option>
                                        <option value="cypriot">Cypriot</option>
                                        <option value="czech">Czech</option>
                                        <option value="danish">Danish</option>
                                        <option value="dominican">Dominican</option>
                                        <option value="dutch">Dutch</option>
                                        <option value="ecuadorian">Ecuadorian</option>
                                        <option value="egyptian">Egyptian</option>
                                        <option value="emirati">Emirati</option>
                                        <option value="estonian">Estonian</option>
                                        <option value="ethiopian">Ethiopian</option>
                                        <option value="filipino">Filipino</option>
                                        <option value="finnish">Finnish</option>
                                        <option value="french">French</option>
                                        <option value="georgian">Georgian</option>
                                        <option value="german">German</option>
                                        <option value="ghanaian">Ghanaian</option>
                                        <option value="greek">Greek</option>
                                        <option value="guatemalan">Guatemalan</option>
                                        <option value="honduran">Honduran</option>
                                        <option value="hungarian">Hungarian</option>
                                        <option value="icelandic">Icelandic</option>
                                        <option value="indian">Indian</option>
                                        <option value="indonesian">Indonesian</option>
                                        <option value="iranian">Iranian</option>
                                        <option value="iraqi">Iraqi</option>
                                        <option value="irish">Irish</option>
                                        <option value="israeli">Israeli</option>
                                        <option value="italian">Italian</option>
                                        <option value="jamaican">Jamaican</option>
                                        <option value="japanese">Japanese</option>
                                        <option value="jordanian">Jordanian</option>
                                        <option value="kazakh">Kazakh</option>
                                        <option value="kenyan">Kenyan</option>
                                        <option value="kuwaiti">Kuwaiti</option>
                                        <option value="latvian">Latvian</option>
                                        <option value="lebanese">Lebanese</option>
                                        <option value="libyan">Libyan</option>
                                        <option value="lithuanian">Lithuanian</option>
                                        <option value="luxembourgish">Luxembourgish</option>
                                        <option value="malaysian">Malaysian</option>
                                        <option value="maltese">Maltese</option>
                                        <option value="mexican">Mexican</option>
                                        <option value="moldovan">Moldovan</option>
                                        <option value="mongolian">Mongolian</option>
                                        <option value="moroccan">Moroccan</option>
                                        <option value="nepalese">Nepalese</option>
                                        <option value="new-zealander">New Zealander</option>
                                        <option value="nicaraguan">Nicaraguan</option>
                                        <option value="nigerian">Nigerian</option>
                                        <option value="norwegian">Norwegian</option>
                                        <option value="pakistani">Pakistani</option>
                                        <option value="panamanian">Panamanian</option>
                                        <option value="paraguayan">Paraguayan</option>
                                        <option value="peruvian">Peruvian</option>
                                        <option value="polish">Polish</option>
                                        <option value="portuguese">Portuguese</option>
                                        <option value="qatari">Qatari</option>
                                        <option value="romanian">Romanian</option>
                                        <option value="russian">Russian</option>
                                        <option value="saudi">Saudi</option>
                                        <option value="senegalese">Senegalese</option>
                                        <option value="serbian">Serbian</option>
                                        <option value="singaporean">Singaporean</option>
                                        <option value="slovak">Slovak</option>
                                        <option value="slovenian">Slovenian</option>
                                        <option value="south-african">South African</option>
                                        <option value="south-korean">South Korean</option>
                                        <option value="spanish">Spanish</option>
                                        <option value="sri-lankan">Sri Lankan</option>
                                        <option value="sudanese">Sudanese</option>
                                        <option value="swedish">Swedish</option>
                                        <option value="swiss">Swiss</option>
                                        <option value="syrian">Syrian</option>
                                        <option value="taiwanese">Taiwanese</option>
                                        <option value="thai">Thai</option>
                                        <option value="tunisian">Tunisian</option>
                                        <option value="turkish">Turkish</option>
                                        <option value="ukrainian">Ukrainian</option>
                                        <option value="uruguayan">Uruguayan</option>
                                        <option value="uzbek">Uzbek</option>
                                        <option value="venezuelan">Venezuelan</option>
                                        <option value="vietnamese">Vietnamese</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="hp-enrollment-form-group">
                                    <label>Mother Tongue *</label>
                                    <select name="studentMotherTongue" required>
                                        <option value="">Please select</option>
                                        <option value="arabic">Arabic</option>
                                        <option value="bengali">Bengali</option>
                                        <option value="chinese-mandarin">Chinese (Mandarin)</option>
                                        <option value="chinese-cantonese">Chinese (Cantonese)</option>
                                        <option value="dutch">Dutch</option>
                                        <option value="english">English</option>
                                        <option value="french">French</option>
                                        <option value="german">German</option>
                                        <option value="greek">Greek</option>
                                        <option value="hindi">Hindi</option>
                                        <option value="italian">Italian</option>
                                        <option value="japanese">Japanese</option>
                                        <option value="korean">Korean</option>
                                        <option value="polish">Polish</option>
                                        <option value="portuguese">Portuguese</option>
                                        <option value="russian">Russian</option>
                                        <option value="spanish">Spanish</option>
                                        <option value="swedish">Swedish</option>
                                        <option value="thai">Thai</option>
                                        <option value="turkish">Turkish</option>
                                        <option value="ukrainian">Ukrainian</option>
                                        <option value="urdu">Urdu</option>
                                        <option value="vietnamese">Vietnamese</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="hp-enrollment-form-row">
                                <div class="hp-enrollment-form-group">
                                    <label>E-Mail *</label>
                                    <input type="email" name="studentEmail" required>
                                </div>
                                <div class="hp-enrollment-form-group">
                                    <label>Phone *</label>
                                    <div style="display: flex; gap: 10px;">
                                        <select name="studentPhonePrefix" style="width: 90px; flex-shrink: 0;" required>
                                            <option value="">Prefix</option>
                                            <option value="+1">+1</option>
                                            <option value="+7">+7</option>
                                            <option value="+20">+20</option>
                                            <option value="+27">+27</option>
                                            <option value="+30">+30</option>
                                            <option value="+31">+31</option>
                                            <option value="+32">+32</option>
                                            <option value="+33">+33</option>
                                            <option value="+34">+34</option>
                                            <option value="+36">+36</option>
                                            <option value="+39">+39</option>
                                            <option value="+40">+40</option>
                                            <option value="+41">+41</option>
                                            <option value="+43">+43</option>
                                            <option value="+44">+44</option>
                                            <option value="+45">+45</option>
                                            <option value="+46">+46</option>
                                            <option value="+47">+47</option>
                                            <option value="+48">+48</option>
                                            <option value="+49">+49</option>
                                            <option value="+51">+51</option>
                                            <option value="+52">+52</option>
                                            <option value="+53">+53</option>
                                            <option value="+54">+54</option>
                                            <option value="+55">+55</option>
                                            <option value="+56">+56</option>
                                            <option value="+57">+57</option>
                                            <option value="+58">+58</option>
                                            <option value="+60">+60</option>
                                            <option value="+61">+61</option>
                                            <option value="+62">+62</option>
                                            <option value="+63">+63</option>
                                            <option value="+64">+64</option>
                                            <option value="+65">+65</option>
                                            <option value="+66">+66</option>
                                            <option value="+81">+81</option>
                                            <option value="+82">+82</option>
                                            <option value="+84">+84</option>
                                            <option value="+86">+86</option>
                                            <option value="+90">+90</option>
                                            <option value="+91">+91</option>
                                            <option value="+92">+92</option>
                                            <option value="+212">+212</option>
                                            <option value="+213">+213</option>
                                            <option value="+216">+216</option>
                                            <option value="+234">+234</option>
                                            <option value="+254">+254</option>
                                            <option value="+351">+351</option>
                                            <option value="+352">+352</option>
                                            <option value="+353">+353</option>
                                            <option value="+356">+356</option>
                                            <option value="+357">+357</option>
                                            <option value="+358">+358</option>
                                            <option value="+359">+359</option>
                                            <option value="+370">+370</option>
                                            <option value="+371">+371</option>
                                            <option value="+372">+372</option>
                                            <option value="+380">+380</option>
                                            <option value="+385">+385</option>
                                            <option value="+386">+386</option>
                                            <option value="+420">+420</option>
                                            <option value="+421">+421</option>
                                            <option value="+502">+502</option>
                                            <option value="+503">+503</option>
                                            <option value="+504">+504</option>
                                            <option value="+505">+505</option>
                                            <option value="+506">+506</option>
                                            <option value="+507">+507</option>
                                            <option value="+509">+509</option>
                                            <option value="+591">+591</option>
                                            <option value="+593">+593</option>
                                            <option value="+595">+595</option>
                                            <option value="+598">+598</option>
                                            <option value="+852">+852</option>
                                            <option value="+880">+880</option>
                                            <option value="+886">+886</option>
                                            <option value="+961">+961</option>
                                            <option value="+962">+962</option>
                                            <option value="+965">+965</option>
                                            <option value="+966">+966</option>
                                            <option value="+968">+968</option>
                                            <option value="+971">+971</option>
                                            <option value="+972">+972</option>
                                            <option value="+973">+973</option>
                                            <option value="+974">+974</option>
                                        </select>
                                        <input type="tel" name="studentPhone" placeholder="Phone number" style="flex: 1;" required>
                                    </div>
                                </div>
                            </div>

                            <h4 style="margin: 25px 0 15px 0; color: var(--primary-dark);">Student Information</h4>

                            <div class="hp-enrollment-form-group">
                                <label>Passport Number *</label>
                                <input type="text" name="studentPassportNumber" required>
                            </div>

                            <div class="hp-enrollment-form-group">
                                <label>Address *</label>
                                <input type="text" name="studentAddress" required>
                            </div>

                            <div class="hp-enrollment-form-row">
                                <div class="hp-enrollment-form-group">
                                    <label>City *</label>
                                    <input type="text" name="studentCity" required>
                                </div>
                                <div class="hp-enrollment-form-group">
                                    <label>ZIP/Postal Code *</label>
                                    <input type="text" name="studentZipCode" required>
                                </div>
                            </div>

                            <div class="hp-enrollment-form-group">
                                <label>Country *</label>
                                <select name="studentCountry" required>
                                    <option value="">Please select</option>
                                    <option value="afghanistan">Afghanistan</option>
                                    <option value="albania">Albania</option>
                                    <option value="algeria">Algeria</option>
                                    <option value="andorra">Andorra</option>
                                    <option value="angola">Angola</option>
                                    <option value="argentina">Argentina</option>
                                    <option value="armenia">Armenia</option>
                                    <option value="australia">Australia</option>
                                    <option value="austria">Austria</option>
                                    <option value="azerbaijan">Azerbaijan</option>
                                    <option value="bahrain">Bahrain</option>
                                    <option value="bangladesh">Bangladesh</option>
                                    <option value="belarus">Belarus</option>
                                    <option value="belgium">Belgium</option>
                                    <option value="bolivia">Bolivia</option>
                                    <option value="bosnia">Bosnia and Herzegovina</option>
                                    <option value="brazil">Brazil</option>
                                    <option value="bulgaria">Bulgaria</option>
                                    <option value="cambodia">Cambodia</option>
                                    <option value="cameroon">Cameroon</option>
                                    <option value="canada">Canada</option>
                                    <option value="chile">Chile</option>
                                    <option value="china">China</option>
                                    <option value="colombia">Colombia</option>
                                    <option value="costa-rica">Costa Rica</option>
                                    <option value="croatia">Croatia</option>
                                    <option value="cuba">Cuba</option>
                                    <option value="cyprus">Cyprus</option>
                                    <option value="czech-republic">Czech Republic</option>
                                    <option value="denmark">Denmark</option>
                                    <option value="dominican-republic">Dominican Republic</option>
                                    <option value="ecuador">Ecuador</option>
                                    <option value="egypt">Egypt</option>
                                    <option value="estonia">Estonia</option>
                                    <option value="ethiopia">Ethiopia</option>
                                    <option value="finland">Finland</option>
                                    <option value="france">France</option>
                                    <option value="georgia">Georgia</option>
                                    <option value="germany">Germany</option>
                                    <option value="ghana">Ghana</option>
                                    <option value="greece">Greece</option>
                                    <option value="guatemala">Guatemala</option>
                                    <option value="honduras">Honduras</option>
                                    <option value="hungary">Hungary</option>
                                    <option value="iceland">Iceland</option>
                                    <option value="india">India</option>
                                    <option value="indonesia">Indonesia</option>
                                    <option value="iran">Iran</option>
                                    <option value="iraq">Iraq</option>
                                    <option value="ireland">Ireland</option>
                                    <option value="israel">Israel</option>
                                    <option value="italy">Italy</option>
                                    <option value="jamaica">Jamaica</option>
                                    <option value="japan">Japan</option>
                                    <option value="jordan">Jordan</option>
                                    <option value="kazakhstan">Kazakhstan</option>
                                    <option value="kenya">Kenya</option>
                                    <option value="kuwait">Kuwait</option>
                                    <option value="latvia">Latvia</option>
                                    <option value="lebanon">Lebanon</option>
                                    <option value="libya">Libya</option>
                                    <option value="lithuania">Lithuania</option>
                                    <option value="luxembourg">Luxembourg</option>
                                    <option value="malaysia">Malaysia</option>
                                    <option value="malta">Malta</option>
                                    <option value="mexico">Mexico</option>
                                    <option value="moldova">Moldova</option>
                                    <option value="mongolia">Mongolia</option>
                                    <option value="morocco">Morocco</option>
                                    <option value="nepal">Nepal</option>
                                    <option value="netherlands">Netherlands</option>
                                    <option value="new-zealand">New Zealand</option>
                                    <option value="nicaragua">Nicaragua</option>
                                    <option value="nigeria">Nigeria</option>
                                    <option value="norway">Norway</option>
                                    <option value="pakistan">Pakistan</option>
                                    <option value="panama">Panama</option>
                                    <option value="paraguay">Paraguay</option>
                                    <option value="peru">Peru</option>
                                    <option value="philippines">Philippines</option>
                                    <option value="poland">Poland</option>
                                    <option value="portugal">Portugal</option>
                                    <option value="qatar">Qatar</option>
                                    <option value="romania">Romania</option>
                                    <option value="russia">Russia</option>
                                    <option value="saudi-arabia">Saudi Arabia</option>
                                    <option value="senegal">Senegal</option>
                                    <option value="serbia">Serbia</option>
                                    <option value="singapore">Singapore</option>
                                    <option value="slovakia">Slovakia</option>
                                    <option value="slovenia">Slovenia</option>
                                    <option value="south-africa">South Africa</option>
                                    <option value="south-korea">South Korea</option>
                                    <option value="spain">Spain</option>
                                    <option value="sri-lanka">Sri Lanka</option>
                                    <option value="sudan">Sudan</option>
                                    <option value="sweden">Sweden</option>
                                    <option value="switzerland">Switzerland</option>
                                    <option value="syria">Syria</option>
                                    <option value="taiwan">Taiwan</option>
                                    <option value="thailand">Thailand</option>
                                    <option value="tunisia">Tunisia</option>
                                    <option value="turkey">Turkey</option>
                                    <option value="uae">United Arab Emirates</option>
                                    <option value="uk">United Kingdom</option>
                                    <option value="ukraine">Ukraine</option>
                                    <option value="uruguay">Uruguay</option>
                                    <option value="usa">United States</option>
                                    <option value="uzbekistan">Uzbekistan</option>
                                    <option value="venezuela">Venezuela</option>
                                    <option value="vietnam">Vietnam</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>

                        <!-- Step 4: Visa -->
                        <div class="hp-enrollment-form-step" data-step="4">
                            <h3 style="margin-bottom: 25px; color: var(--primary-dark);">Visa Information</h3>

                            <div class="hp-enrollment-form-group">
                                <label>Admission Letter Required *</label>
                                <input type="hidden" name="admissionLetterRequired" id="admissionLetterRequiredInput" required>
                                <div class="hp-housing-options">
                                    <div class="hp-housing-option hp-admission-option" data-value="yes">Yes</div>
                                    <div class="hp-housing-option hp-admission-option" data-value="no">No</div>
                                </div>
                            </div>

                            <div id="visaDetailsContainer" style="display: none;">
                                <div class="hp-enrollment-form-group">
                                    <label>Passport Photo *</label>
                                    <div class="hp-file-upload-container" id="passportFrontContainer">
                                        <input type="file" name="passportPhoto" id="passportFrontInput" accept="image/*,.pdf" style="display: none;">
                                        <div class="hp-file-upload-box" id="passportFrontBox" onclick="document.getElementById('passportFrontInput').click()">
                                            <i data-lucide="upload" style="width: 40px; height: 40px; color: #9575cd; margin-bottom: 10px;"></i>
                                            <span>Click to upload passport</span>
                                            <small style="color: #999;">JPG, PNG or PDF</small>
                                        </div>
                                        <div class="hp-file-preview" id="passportFrontPreview" style="display: none;">
                                            <img id="passportFrontImage" src="" alt="Passport front">
                                            <div class="hp-file-preview-info">
                                                <span id="passportFrontName"></span>
                                                <button type="button" class="hp-file-remove-btn" onclick="removePassportFile('front')">&times;</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="hp-enrollment-form-group">
                                    <label>Additional Comments</label>
                                    <textarea name="visaComments" rows="4"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Step 5: Insurance -->
                        <div class="hp-enrollment-form-step" data-step="5">
                            <h3 style="margin-bottom: 25px; color: var(--primary-dark);">Insurance Information</h3>

                            <div class="hp-enrollment-form-group">
                                <label>Insurance Required *</label>
                                <input type="hidden" name="insuranceRequired" id="insuranceRequiredInput" required>
                                <div class="hp-housing-options">
                                    <div class="hp-housing-option hp-insurance-option" data-value="yes">Yes</div>
                                    <div class="hp-housing-option hp-insurance-option" data-value="no">No</div>
                                </div>
                            </div>

                            <div id="insuranceDetailsContainer" style="display: none;">
                                <!-- Swisscare Info -->
                                <div style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 12px; padding: 20px; margin-bottom: 25px; border-left: 4px solid #7c3aed;">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                        <img src="https://www.swisscare.com/images/swisscare-logo.svg" alt="Swisscare" style="height: 24px;" onerror="this.style.display='none'">
                                        <span style="font-weight: 600; color: #7c3aed; font-size: 18px;">Swisscare</span>
                                    </div>
                                    <p style="margin: 0; color: #495057; font-size: 15px;">International Student Health Insurance Spain</p>
                                    <p style="margin: 5px 0 0 0; color: #6c757d; font-size: 13px;">For foreign students studying in Spain</p>
                                </div>

                                <!-- Insurance Plan Cards -->
                                <div class="hp-enrollment-form-group">
                                    <label>Select Insurance Plan *</label>
                                    <input type="hidden" name="insuranceType" id="insuranceTypeInput">
                                    <div class="hp-insurance-grid">
                                        <!-- Standard Card -->
                                        <div class="hp-insurance-card" data-plan="standard" style="border: 2px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: white; position: relative;">
                                            <div class="hp-insurance-check" style="position: absolute; top: 10px; right: 10px; width: 24px; height: 24px; border-radius: 50%; background: #7c3aed; display: none; align-items: center; justify-content: center;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            </div>
                                            <h4 style="margin: 0 0 10px 0; color: #7c3aed; font-size: 16px; font-weight: 700; text-transform: uppercase;">Standard</h4>
                                            <p style="margin: 0; color: #6c757d; font-size: 13px;">Max sum</p>
                                            <p style="margin: 0 0 15px 0; color: #495057; font-size: 14px;">€ 50,000</p>
                                            <p style="margin: 0; font-size: 13px; color: #6c757d;">From</p>
                                            <p style="margin: 0; font-size: 24px; font-weight: 700; color: #333;">EUR 1.00</p>
                                            <p style="margin: 0; font-size: 13px; color: #6c757d;">per day</p>
                                        </div>
                                        <!-- Comfort Card -->
                                        <div class="hp-insurance-card" data-plan="comfort" style="border: 2px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: white; position: relative;">
                                            <div class="hp-insurance-check" style="position: absolute; top: 10px; right: 10px; width: 24px; height: 24px; border-radius: 50%; background: #7c3aed; display: none; align-items: center; justify-content: center;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            </div>
                                            <h4 style="margin: 0 0 10px 0; color: #495057; font-size: 16px; font-weight: 700; text-transform: uppercase;">Comfort</h4>
                                            <p style="margin: 0; color: #6c757d; font-size: 13px;">Max sum</p>
                                            <p style="margin: 0 0 15px 0; color: #495057; font-size: 14px;">€ 150,000</p>
                                            <p style="margin: 0; font-size: 13px; color: #6c757d;">From</p>
                                            <p style="margin: 0; font-size: 24px; font-weight: 700; color: #333;">EUR 1.50</p>
                                            <p style="margin: 0; font-size: 13px; color: #6c757d;">per day</p>
                                        </div>
                                        <!-- Premium Card -->
                                        <div class="hp-insurance-card" data-plan="premium" style="border: 2px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: white; position: relative;">
                                            <div class="hp-insurance-check" style="position: absolute; top: 10px; right: 10px; width: 24px; height: 24px; border-radius: 50%; background: #7c3aed; display: none; align-items: center; justify-content: center;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            </div>
                                            <h4 style="margin: 0 0 10px 0; color: #495057; font-size: 16px; font-weight: 700; text-transform: uppercase;">Premium</h4>
                                            <p style="margin: 0; color: #6c757d; font-size: 13px;">Max sum</p>
                                            <p style="margin: 0 0 15px 0; color: #495057; font-size: 14px;">€ 500,000</p>
                                            <p style="margin: 0; font-size: 13px; color: #6c757d;">From</p>
                                            <p style="margin: 0; font-size: 24px; font-weight: 700; color: #333;">EUR 2.00</p>
                                            <p style="margin: 0; font-size: 13px; color: #6c757d;">per day</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Insurance Dates -->
                                <div class="hp-enrollment-form-row" style="margin-top: 20px;">
                                    <div class="hp-enrollment-form-group">
                                        <label>Insurance Start Date *</label>
                                        <input type="text" name="insuranceStartDate" id="insuranceStartDateInput" placeholder="Select date">
                                    </div>
                                    <div class="hp-enrollment-form-group">
                                        <label>Insurance End Date *</label>
                                        <input type="text" name="insuranceEndDate" id="insuranceEndDateInput" placeholder="Select date">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 6: Agency Information -->
                        <div class="hp-enrollment-form-step" data-step="6">
                            <h3 style="margin-bottom: 25px; color: var(--primary-dark);">Agency Information</h3>

                            <div class="hp-enrollment-form-group">
                                <label>Agency Name *</label>
                                <input type="text" name="agencyName" required>
                            </div>

                            <div class="hp-enrollment-form-row">
                                <div class="hp-enrollment-form-group">
                                    <label>Contact Person *</label>
                                    <input type="text" name="agencyContact" required>
                                </div>
                                <div class="hp-enrollment-form-group">
                                    <label>Agency Email *</label>
                                    <input type="email" name="agencyEmail" required>
                                </div>
                            </div>

                            <div class="hp-enrollment-form-group">
                                <label>Agency Phone</label>
                                <input type="tel" name="agencyPhone">
                            </div>

                            <div class="hp-enrollment-form-group">
                                <label>Additional Notes</label>
                                <textarea name="agencyNotes" rows="4"></textarea>
                            </div>
                        </div>

                        <!-- Step 7: Summary -->
                        <div class="hp-enrollment-form-step" data-step="7">
                            <h3 style="margin-bottom: 25px; color: var(--primary-dark);">Summary</h3>
                            <div id="enrollmentSummary" style="background: #f8f8f8; padding: 25px; border-radius: 10px; line-height: 1.8;">
                                <!-- Summary will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>

                    <div class="hp-enrollment-modal-footer">
                        <button type="button" class="hp-enrollment-btn hp-enrollment-btn-secondary" id="prevBtn" onclick="changeStep(-1)">Back</button>
                        <button type="button" class="hp-enrollment-btn hp-enrollment-btn-primary" id="nextBtn" onclick="changeStep(1)">Next</button>
                    </div>
                </form>
            </div>
        </div>
        </div>
    </div>

    <script>
    function toggleAccordion(header) {
        const item = header.parentElement;
        const isActive = item.classList.contains('active');

        // Close all items
        document.querySelectorAll('.hp-accordion-item').forEach(function(el) {
            el.classList.remove('active');
        });

        // Open clicked item if it wasn't active
        if (!isActive) {
            item.classList.add('active');
        }
    }

    // Mobile Menu Toggle
    const menuToggle = document.querySelector('.hp-menu-toggle');
    const mobileMenu = document.querySelector('.hp-mobile-menu');
    const mobileMenuOverlay = document.querySelector('.hp-mobile-menu-overlay');

    if (menuToggle && mobileMenu && mobileMenuOverlay) {
        menuToggle.addEventListener('click', function() {
            this.classList.toggle('active');
            mobileMenu.classList.toggle('active');
            mobileMenuOverlay.classList.toggle('active');
            document.body.style.overflow = mobileMenu.classList.contains('active') ? 'hidden' : '';
        });

        // Close menu when clicking overlay
        mobileMenuOverlay.addEventListener('click', function() {
            menuToggle.classList.remove('active');
            mobileMenu.classList.remove('active');
            this.classList.remove('active');
            document.body.style.overflow = '';
        });

        // Close menu when clicking a link
        document.querySelectorAll('.hp-mobile-menu a').forEach(function(link) {
            link.addEventListener('click', function() {
                menuToggle.classList.remove('active');
                mobileMenu.classList.remove('active');
                mobileMenuOverlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        });
    }

    // Modal Functions
    function openPathwayModal(event) {
        event.preventDefault();
        const modal = document.getElementById('pathwayModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Ensure first tab is active
        setTimeout(() => {
            const firstTab = modal.querySelector('.hp-tab-btn');
            const firstContent = modal.querySelector('.hp-tab-content');
            if (firstTab) firstTab.classList.add('active');
            if (firstContent) firstContent.classList.add('active');
        }, 10);

        // Reinitialize Lucide icons in the modal
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function closePathwayModal() {
        const modal = document.getElementById('pathwayModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function openImmersionModal(event) {
        event.preventDefault();
        const modal = document.getElementById('immersionModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Ensure first tab is active
        setTimeout(() => {
            const firstTab = modal.querySelector('.hp-tab-btn');
            const firstContent = modal.querySelector('.hp-tab-content');
            if (firstTab) firstTab.classList.add('active');
            if (firstContent) firstContent.classList.add('active');
        }, 10);

        // Reinitialize Lucide icons in the modal
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function closeImmersionModal() {
        const modal = document.getElementById('immersionModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function openStudyTripsModal(event) {
        event.preventDefault();
        const modal = document.getElementById('studyTripsModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Ensure first tab is active
        setTimeout(() => {
            const firstTab = modal.querySelector('.hp-tab-btn');
            const firstContent = modal.querySelector('.hp-tab-content');
            if (firstTab) firstTab.classList.add('active');
            if (firstContent) firstContent.classList.add('active');
        }, 10);

        // Reinitialize Lucide icons in the modal
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function closeStudyTripsModal() {
        const modal = document.getElementById('studyTripsModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function openTeacherTrainingModal(event) {
        event.preventDefault();
        const modal = document.getElementById('teacherTrainingModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Ensure first tab is active
        setTimeout(() => {
            const firstTab = modal.querySelector('.hp-tab-btn');
            const firstContent = modal.querySelector('.hp-tab-content');
            if (firstTab) firstTab.classList.add('active');
            if (firstContent) firstContent.classList.add('active');
        }, 10);

        // Reinitialize Lucide icons in the modal
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function closeTeacherTrainingModal() {
        const modal = document.getElementById('teacherTrainingModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function switchTab(event, tabId) {
        // Remove active class from all tabs and content
        document.querySelectorAll('.hp-tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.hp-tab-content').forEach(content => content.classList.remove('active'));

        // Add active class to clicked tab and corresponding content
        event.target.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    }

    // Mila Modal Functions
    function openMilaModal(event) {
        event.preventDefault();
        const modal = document.getElementById('milaModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Reinitialize Lucide icons in the modal
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function closeMilaModal() {
        const modal = document.getElementById('milaModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Miriam Modal Functions
    function openMiriamModal(event) {
        event.preventDefault();
        const modal = document.getElementById('miriamModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Reinitialize Lucide icons in the modal
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function closeMiriamModal() {
        const modal = document.getElementById('miriamModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function openYanaModal(event) {
        event.preventDefault();
        const modal = document.getElementById('yanaModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Reinitialize Lucide icons in the modal
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function closeYanaModal() {
        const modal = document.getElementById('yanaModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function openPiotrModal(event) {
        event.preventDefault();
        const modal = document.getElementById('piotrModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Reinitialize Lucide icons in the modal
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function closePiotrModal() {
        const modal = document.getElementById('piotrModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    function openPeterModal(event) {
        event.preventDefault();
        const modal = document.getElementById('peterModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Reinitialize Lucide icons in the modal
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function closePeterModal() {
        const modal = document.getElementById('peterModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Close modal when pressing Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closePathwayModal();
            closeImmersionModal();
            closeStudyTripsModal();
            closeTeacherTrainingModal();
            closeMilaModal();
            closeMiriamModal();
            closeYanaModal();
            closePiotrModal();
            closePeterModal();
        }
    });

    // Initialize Lucide icons
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // Close mobile menu when clicking on anchor links
    document.querySelectorAll('.hp-mobile-menu a[href^="#"]').forEach(link => {
        link.addEventListener('click', function() {
            const menuToggle = document.querySelector('.hp-menu-toggle');
            const mobileMenu = document.querySelector('.hp-mobile-menu');
            const overlay = document.querySelector('.hp-mobile-menu-overlay');

            menuToggle.classList.remove('active');
            mobileMenu.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        });
    });

    // Close mobile menu when clicking on desktop nav links
    document.querySelectorAll('.hp-nav a[href^="#"]').forEach(link => {
        link.addEventListener('click', function(e) {
            // Allow default anchor behavior (smooth scroll)
        });
    });

    // Handle resource downloads
    function downloadResource(event, resourceType) {
        event.preventDefault();
        event.stopPropagation();

        // Resource download links
        const resourcePaths = {
            'visa-application': 'https://www.hablandis.com/wp-content/uploads/2026/01/Student_Visa_Requirements_2026_Hablandis.pdf',
            'price-list': 'https://www.hablandis.com/wp-content/uploads/2026/01/Spanish-Courses-2026-Price-List-Terms-Conditions.pdf',
            'teacher-training-price-list': 'https://www.hablandis.com/wp-content/uploads/2026/01/Teacher_Training_Price_List_2026_Hablandis.pdf',
            'pathway-price-list': 'https://www.hablandis.com/wp-content/uploads/2026/01/Pathway_Program_Price_List_2026_Hablandis.pdf',
            'agent-manual': 'https://www.hablandis.com/wp-content/uploads/2026/01/Agent-Manual-2026.pdf',
            'marketing-kit': '<?php echo get_stylesheet_directory_uri(); ?>/resources/marketing-kit-2024.zip',
            'partnership-agreement': '<?php echo get_stylesheet_directory_uri(); ?>/resources/partnership-agreement.docx',
            'program-guides': '<?php echo get_stylesheet_directory_uri(); ?>/resources/program-guides.pdf',
            'training-videos': '<?php echo get_stylesheet_directory_uri(); ?>/resources/training-videos.zip',
            'application-forms': '<?php echo get_stylesheet_directory_uri(); ?>/resources/application-forms.zip',
            'commission-guide': '<?php echo get_stylesheet_directory_uri(); ?>/resources/commission-structure.pdf',
            'brand-guidelines': '<?php echo get_stylesheet_directory_uri(); ?>/resources/brand-guidelines.pdf',
            'social-templates': '<?php echo get_stylesheet_directory_uri(); ?>/resources/social-templates.zip',
            'data-policy': '<?php echo get_stylesheet_directory_uri(); ?>/resources/data-protection-policy.pdf'
        };

        const filePath = resourcePaths[resourceType];

        if (filePath) {
            // Create a temporary anchor element to trigger download
            const link = document.createElement('a');
            link.href = filePath;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Optional: Track download analytics
            console.log('Downloaded resource:', resourceType);
        } else {
            console.error('Resource not found:', resourceType);
        }
    }

    // Toggle Resource Accordion (Proposal 2)
    function toggleResourceAccordion(event, categoryId) {
        event.preventDefault();
        const button = event.currentTarget;
        const accordionItem = button.closest('.hp-accordion-item-resource');
        const content = accordionItem.querySelector('.hp-accordion-content-resource');

        // Toggle active class
        accordionItem.classList.toggle('active');

        // Reinitialize Lucide icons after accordion opens
        if (accordionItem.classList.contains('active')) {
            setTimeout(() => {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }, 100);
        }
    }

    // FAQ Toggle Function
    function toggleFAQ(event, faqId) {
        event.preventDefault();
        const button = event.currentTarget;
        const faqItem = button.closest('.hp-faq-item');

        // Toggle active class
        faqItem.classList.toggle('active');

        // Reinitialize Lucide icons after opening
        if (faqItem.classList.contains('active')) {
            setTimeout(() => {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }, 100);
        }
    }

    // Enrollment Form Modal Functions
    let currentStep = 1;
    const totalSteps = 7;

    function openEnrollmentModal(event) {
        if (event) {
            event.preventDefault();
        }
        document.getElementById('enrollmentModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeEnrollmentModal() {
        document.getElementById('enrollmentModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }

    function showStep(step) {
        // Hide all steps
        document.querySelectorAll('.hp-enrollment-form-step').forEach(el => {
            el.classList.remove('active');
        });

        // Show current step
        const currentStepEl = document.querySelector(`.hp-enrollment-form-step[data-step="${step}"]`);
        if (currentStepEl) {
            currentStepEl.classList.add('active');
        }

        // Update progress bar
        document.querySelectorAll('.hp-enrollment-step').forEach((el, index) => {
            const stepNum = index + 1;
            el.classList.remove('active', 'completed');

            if (stepNum < step) {
                el.classList.add('completed');
            } else if (stepNum === step) {
                el.classList.add('active');
            }
        });

        // Update buttons
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');

        prevBtn.style.display = step === 1 ? 'none' : 'block';

        if (step === totalSteps) {
            nextBtn.textContent = 'Submit';
            nextBtn.onclick = function() { submitEnrollmentForm(); };
        } else {
            nextBtn.textContent = 'Next';
            nextBtn.onclick = function() { changeStep(1); };
        }

        // If step 7, generate summary
        if (step === 7) {
            generateSummary();
        }

        // Scroll to top of modal content when changing steps
        const modalContent = document.querySelector('.hp-enrollment-modal-content');
        if (modalContent) {
            modalContent.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    // Force English locale for date inputs
    document.documentElement.lang = 'en';
    document.documentElement.setAttribute('lang', 'en');

    // Course Category Selection Handler
    document.addEventListener('DOMContentLoaded', function() {
        const categoryOptions = document.querySelectorAll('.hp-category-option');
        const courseTypeInput = document.getElementById('courseTypeInput');
        const courseCategoryInput = document.getElementById('courseCategoryInput');

        // Detail containers
        const pathwayDetails = document.getElementById('pathwayDetails');
        const longTermDetails = document.getElementById('longTermDetails');
        const shortTermDetails = document.getElementById('shortTermDetails');
        const summerDetails = document.getElementById('summerDetails');
        const teacherDetails = document.getElementById('teacherDetails');
        const immersionDetails = document.getElementById('immersionDetails');

        // Course selects
        const pathwayProgramSelect = document.getElementById('pathwayProgramSelect');
        const longTermDurationSelect = document.getElementById('longTermDurationSelect');
        const shortTermCourseSelect = document.getElementById('shortTermCourseSelect');
        const summerCourseSelect = document.getElementById('summerCourseSelect');
        const teacherCourseSelect = document.getElementById('teacherCourseSelect');
        const immersionCourseSelect = document.getElementById('immersionCourseSelect');

        // Map categories to their detail containers and selects
        const categoryMap = {
            'pathway': { details: pathwayDetails, select: pathwayProgramSelect, courseType: 'pathway-program' },
            'long-term': { details: longTermDetails, select: longTermDurationSelect, courseType: 'long-term-intensive' },
            'short-term': { details: shortTermDetails, select: shortTermCourseSelect },
            'summer': { details: summerDetails, select: summerCourseSelect },
            'teacher': { details: teacherDetails, select: teacherCourseSelect },
            'immersion': { details: immersionDetails, select: immersionCourseSelect }
        };

        // Hide all detail containers
        function hideAllDetails() {
            Object.values(categoryMap).forEach(item => {
                if (item.details) {
                    item.details.style.display = 'none';
                    // Reset the select value
                    if (item.select) {
                        item.select.value = '';
                    }
                }
            });
            // Reset course type input
            courseTypeInput.value = '';
        }

        // Category selection handler
        categoryOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all category options
                categoryOptions.forEach(opt => opt.classList.remove('selected'));

                // Add selected class to clicked option
                this.classList.add('selected');

                // Get selected category
                const category = this.getAttribute('data-category');

                // Update hidden input
                courseCategoryInput.value = category;

                // Hide all details first
                hideAllDetails();

                // Show the selected category's details
                if (categoryMap[category] && categoryMap[category].details) {
                    categoryMap[category].details.style.display = 'block';
                }

                // Set courseType for categories with fixed course type (pathway and long-term)
                if (categoryMap[category] && categoryMap[category].courseType) {
                    courseTypeInput.value = categoryMap[category].courseType;
                }

                // Remove any error styling
                this.style.borderColor = '';
            });
        });

        // Course select change handlers - update courseTypeInput when a course is selected
        [shortTermCourseSelect, summerCourseSelect, teacherCourseSelect, immersionCourseSelect].forEach(select => {
            if (select) {
                select.addEventListener('change', function() {
                    courseTypeInput.value = this.value;
                });
            }
        });

        // Initialize Flatpickr for all date inputs
        function initializeDatePickers() {
            const today = new Date();
            const currentDay = today.getDay();
            const daysUntilMonday = currentDay === 0 ? 1 : (8 - currentDay) % 7;
            const nextMonday = new Date(today);
            nextMonday.setDate(today.getDate() + daysUntilMonday);

            // Monday-only date pickers (Long-term, Short-term)
            const mondayDateInputs = [
                document.getElementById('startDateInput'),           // Long-term
                document.getElementById('shortTermStartDateInput')   // Short-term
            ];

            mondayDateInputs.forEach(dateInput => {
                if (dateInput) {
                    flatpickr(dateInput, {
                        locale: 'en',
                        dateFormat: 'Y-m-d',
                        minDate: nextMonday,
                        maxDate: new Date(today.getFullYear() + 5, 11, 31),
                        showMonths: 1,
                        monthSelectorType: 'dropdown',
                        disable: [
                            function(date) {
                                return date.getDay() !== 1;
                            }
                        ],
                        onReady: function(selectedDates, dateStr, instance) {
                            const yearElement = instance.currentYearElement;
                            if (yearElement) {
                                const currentYear = today.getFullYear();
                                const yearSelect = document.createElement('select');
                                yearSelect.className = 'flatpickr-year-select';

                                for (let year = currentYear; year <= currentYear + 5; year++) {
                                    const option = document.createElement('option');
                                    option.value = year;
                                    option.textContent = year;
                                    yearSelect.appendChild(option);
                                }

                                yearSelect.value = instance.currentYear;

                                yearSelect.addEventListener('change', function() {
                                    instance.changeYear(parseInt(this.value));
                                });

                                yearElement.parentNode.replaceChild(yearSelect, yearElement);
                            }
                        },
                        onChange: function(selectedDates, dateStr, instance) {
                            dateInput.style.borderColor = '#e0e0e0';
                        }
                    });
                }
            });

            // Pathway Start Date picker
            const pathwayStartDateInput = document.getElementById('pathwayStartDateInput');
            if (pathwayStartDateInput) {
                flatpickr(pathwayStartDateInput, {
                    locale: 'en',
                    dateFormat: 'Y-m-d',
                    minDate: 'today',
                    maxDate: new Date(today.getFullYear() + 5, 11, 31)
                });
            }

            // Any-day date picker for Student Groups (Immersion)
            const studentGroupStartDateInput = document.getElementById('studentGroupStartDateInput');
            if (studentGroupStartDateInput) {
                flatpickr(studentGroupStartDateInput, {
                    locale: 'en',
                    dateFormat: 'Y-m-d',
                    minDate: 'today',
                    maxDate: new Date(today.getFullYear() + 5, 11, 31),
                    showMonths: 1,
                    monthSelectorType: 'dropdown',
                    onReady: function(selectedDates, dateStr, instance) {
                        const yearElement = instance.currentYearElement;
                        if (yearElement) {
                            const currentYear = today.getFullYear();
                            const yearSelect = document.createElement('select');
                            yearSelect.className = 'flatpickr-year-select';

                            for (let year = currentYear; year <= currentYear + 5; year++) {
                                const option = document.createElement('option');
                                option.value = year;
                                option.textContent = year;
                                yearSelect.appendChild(option);
                            }

                            yearSelect.value = instance.currentYear;

                            yearSelect.addEventListener('change', function() {
                                instance.changeYear(parseInt(this.value));
                            });

                            yearElement.parentNode.replaceChild(yearSelect, yearElement);
                        }
                    },
                    onChange: function(selectedDates, dateStr, instance) {
                        studentGroupStartDateInput.style.borderColor = '#e0e0e0';
                    }
                });
            }
        }

        // Initialize all date pickers on page load
        initializeDatePickers();

        // Housing Required Handler - Rectangle Selection
        const housingOptions = document.querySelectorAll('.hp-housing-option');
        const housingRequiredInput = document.getElementById('housingRequiredInput');
        const housingDetailsContainer = document.getElementById('housingDetailsContainer');
        const accommodationTypeInputRequired = document.getElementById('accommodationTypeInput');

        housingOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                housingOptions.forEach(opt => opt.classList.remove('selected'));

                // Add selected class to clicked option
                this.classList.add('selected');

                // Update hidden input value
                const value = this.getAttribute('data-value');
                housingRequiredInput.value = value;

                if (value === 'yes') {
                    housingDetailsContainer.style.display = 'block';
                    if (accommodationTypeInputRequired) {
                        accommodationTypeInputRequired.setAttribute('required', 'required');
                    }
                } else if (value === 'no') {
                    housingDetailsContainer.style.display = 'none';
                    if (accommodationTypeInputRequired) {
                        accommodationTypeInputRequired.removeAttribute('required');
                        accommodationTypeInputRequired.value = '';
                    }
                    // Automatically advance to next step
                    setTimeout(function() {
                        changeStep(1);
                    }, 300);
                }
            });
        });

        // Housing Type Handler - Dropdown System
        const accommodationTypeInput = document.getElementById('accommodationTypeInput');
        const roomOptionsContainer = document.getElementById('roomOptionsContainer');
        const housingTypeInput = document.getElementById('housingTypeInput');
        const doubleRoomOccupancyContainer = document.getElementById('doubleRoomOccupancyContainer');
        const doubleRoomOccupancyInput = document.getElementById('doubleRoomOccupancyInput');
        const housingDatesContainer = document.getElementById('housingDatesContainer');
        const checkInDateInput = document.getElementById('checkInDateInput');
        const checkOutDateInput = document.getElementById('checkOutDateInput');

        // Check if all required elements exist before adding event listeners
        if (!accommodationTypeInput || !roomOptionsContainer || !housingTypeInput) {
            console.error('Housing form elements not found');
            return;
        }

        // Room options for each accommodation type with prices
        const roomOptions = {
            'homestay': [
                { value: 'family-single-breakfast', label: 'Single Room - Breakfast (from €280/week)' },
                { value: 'family-single-halfboard', label: 'Single Room - Half Board (from €376/week)' },
                { value: 'family-single-fullboard', label: 'Single Room - Full Board (from €420/week)' },
                { value: 'family-double-breakfast', label: 'Double Room - Breakfast (from €240/week)' },
                { value: 'family-double-halfboard', label: 'Double Room - Half Board (from €336/week)' },
                { value: 'family-double-fullboard', label: 'Double Room - Full Board (from €380/week)' }
            ],
            'student-apartment': [
                { value: 'shared-single', label: 'Single Room (from €590/week)' },
                { value: 'shared-double', label: 'Double Room (from €340/week)' }
            ],
            'private-apartment': [
                { value: 'private-1person', label: 'Private Apartment - 1 Person (from €1,240/week)' },
                { value: 'private-2people', label: 'Private Apartment - 2 People (from €695/week per person)' }
            ]
        };

        // When accommodation type is selected, show room options
        accommodationTypeInput.addEventListener('change', function() {
            const selectedType = this.value;

            if (selectedType && roomOptions[selectedType]) {
                // Populate room options dropdown
                housingTypeInput.innerHTML = '<option value="">Select room option</option>';
                roomOptions[selectedType].forEach(option => {
                    const opt = document.createElement('option');
                    opt.value = option.value;
                    opt.textContent = option.label;
                    housingTypeInput.appendChild(opt);
                });

                // Show room options container
                roomOptionsContainer.style.display = 'block';
                housingTypeInput.setAttribute('required', 'required');

                // Reset subsequent fields
                housingTypeInput.value = '';
                doubleRoomOccupancyContainer.style.display = 'none';
                doubleRoomOccupancyInput.removeAttribute('required');
                doubleRoomOccupancyInput.value = '';
                housingDatesContainer.style.display = 'none';
                checkInDateInput.removeAttribute('required');
                checkOutDateInput.removeAttribute('required');
            } else {
                // Hide all subsequent fields if no type selected
                roomOptionsContainer.style.display = 'none';
                housingTypeInput.removeAttribute('required');
                doubleRoomOccupancyContainer.style.display = 'none';
                doubleRoomOccupancyInput.removeAttribute('required');
                housingDatesContainer.style.display = 'none';
                checkInDateInput.removeAttribute('required');
                checkOutDateInput.removeAttribute('required');
            }
        });

        // When room option is selected, check if it's a double room
        housingTypeInput.addEventListener('change', function() {
            const selectedRoom = this.value;

            if (selectedRoom) {
                // Show housing dates
                housingDatesContainer.style.display = 'block';
                checkInDateInput.setAttribute('required', 'required');
                checkOutDateInput.setAttribute('required', 'required');

                // Check if it's a double room or 2-people apartment
                if (selectedRoom.includes('double') || selectedRoom.includes('2people')) {
                    doubleRoomOccupancyContainer.style.display = 'block';
                    doubleRoomOccupancyInput.setAttribute('required', 'required');
                } else {
                    doubleRoomOccupancyContainer.style.display = 'none';
                    doubleRoomOccupancyInput.removeAttribute('required');
                    doubleRoomOccupancyInput.value = '';
                }
            } else {
                // Hide subsequent fields if no room selected
                doubleRoomOccupancyContainer.style.display = 'none';
                doubleRoomOccupancyInput.removeAttribute('required');
                doubleRoomOccupancyInput.value = '';
                housingDatesContainer.style.display = 'none';
                checkInDateInput.removeAttribute('required');
                checkOutDateInput.removeAttribute('required');
            }
        });

        // Initialize Flatpickr for Check-in Date
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const checkInFlatpickr = flatpickr(checkInDateInput, {
            locale: 'en',
            dateFormat: 'Y-m-d',
            minDate: today,
            onChange: function(selectedDates, dateStr, instance) {
                checkInDateInput.style.borderColor = '#e0e0e0';
                // Update check-out min date to be after check-in
                if (selectedDates[0]) {
                    const minCheckOut = new Date(selectedDates[0]);
                    minCheckOut.setDate(minCheckOut.getDate() + 1);
                    checkOutFlatpickr.set('minDate', minCheckOut);
                }
            }
        });

        // Initialize Flatpickr for Check-out Date
        const checkOutFlatpickr = flatpickr(checkOutDateInput, {
            locale: 'en',
            dateFormat: 'Y-m-d',
            minDate: today,
            onChange: function(selectedDates, dateStr, instance) {
                checkOutDateInput.style.borderColor = '#e0e0e0';
            }
        });

        // Transfer functionality
        const transferRequiredInput = document.getElementById('transferRequiredInput');
        const transferDetailsContainer = document.getElementById('transferDetailsContainer');
        const transferTypeInput = document.getElementById('transferTypeInput');
        const arrivalTransferContainer = document.getElementById('arrivalTransferContainer');
        const departureTransferContainer = document.getElementById('departureTransferContainer');
        const arrivalTransferDateInput = document.getElementById('arrivalTransferDateInput');
        const departureTransferDateInput = document.getElementById('departureTransferDateInput');
        const transferOptions = document.querySelectorAll('.hp-transfer-option');

        // Transfer Required Handler - Rectangle Selection (same as housing)
        transferOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all transfer options
                transferOptions.forEach(opt => opt.classList.remove('selected'));
                // Add selected class to clicked option
                this.classList.add('selected');
                // Update hidden input value
                const value = this.getAttribute('data-value');
                transferRequiredInput.value = value;

                if (value === 'yes') {
                    transferDetailsContainer.style.display = 'block';
                    transferTypeInput.setAttribute('required', 'required');
                } else {
                    transferDetailsContainer.style.display = 'none';
                    transferTypeInput.removeAttribute('required');
                    transferTypeInput.value = '';
                    arrivalTransferContainer.style.display = 'none';
                    departureTransferContainer.style.display = 'none';
                    arrivalTransferDateInput.removeAttribute('required');
                    departureTransferDateInput.removeAttribute('required');
                    arrivalTransferDateInput.value = '';
                    departureTransferDateInput.value = '';
                }
            });
        });

        // Show/hide date fields based on transfer type
        transferTypeInput.addEventListener('change', function() {
            const transferType = this.value;

            // Reset all
            arrivalTransferContainer.style.display = 'none';
            departureTransferContainer.style.display = 'none';
            arrivalTransferDateInput.removeAttribute('required');
            departureTransferDateInput.removeAttribute('required');

            if (transferType === 'arrival') {
                arrivalTransferContainer.style.display = 'block';
                arrivalTransferDateInput.setAttribute('required', 'required');
            } else if (transferType === 'departure') {
                departureTransferContainer.style.display = 'block';
                departureTransferDateInput.setAttribute('required', 'required');
            } else if (transferType === 'both') {
                arrivalTransferContainer.style.display = 'block';
                departureTransferContainer.style.display = 'block';
                arrivalTransferDateInput.setAttribute('required', 'required');
                departureTransferDateInput.setAttribute('required', 'required');
            }
        });

        // Initialize Flatpickr for transfer dates
        flatpickr(arrivalTransferDateInput, {
            locale: 'en',
            dateFormat: 'Y-m-d',
            minDate: today
        });

        flatpickr(departureTransferDateInput, {
            locale: 'en',
            dateFormat: 'Y-m-d',
            minDate: today
        });

        // User Birthdate picker
        const studentDOBInput = document.getElementById('studentDOBInput');
        flatpickr(studentDOBInput, {
            locale: 'en',
            dateFormat: 'Y-m-d',
            defaultDate: null
        });

        // Admission Letter Required Handler - Rectangle Selection
        const admissionLetterRequiredInput = document.getElementById('admissionLetterRequiredInput');
        const admissionOptions = document.querySelectorAll('.hp-admission-option');
        const visaDetailsContainer = document.getElementById('visaDetailsContainer');

        admissionOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all admission options
                admissionOptions.forEach(opt => opt.classList.remove('selected'));
                // Add selected class to clicked option
                this.classList.add('selected');
                // Update hidden input value
                const value = this.getAttribute('data-value');
                admissionLetterRequiredInput.value = value;

                // Show/hide visa details based on selection
                if (value === 'yes') {
                    visaDetailsContainer.style.display = 'block';
                } else {
                    visaDetailsContainer.style.display = 'none';
                }
            });
        });

        // Insurance Required Handler - Rectangle Selection
        const insuranceRequiredInput = document.getElementById('insuranceRequiredInput');
        const insuranceOptions = document.querySelectorAll('.hp-insurance-option');
        const insuranceDetailsContainer = document.getElementById('insuranceDetailsContainer');
        const insuranceTypeInput = document.getElementById('insuranceTypeInput');
        const insuranceStartDateInput = document.getElementById('insuranceStartDateInput');
        const insuranceEndDateInput = document.getElementById('insuranceEndDateInput');

        insuranceOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all insurance options
                insuranceOptions.forEach(opt => opt.classList.remove('selected'));
                // Add selected class to clicked option
                this.classList.add('selected');
                // Update hidden input value
                const value = this.getAttribute('data-value');
                insuranceRequiredInput.value = value;

                // Show/hide insurance details based on selection
                if (value === 'yes') {
                    insuranceDetailsContainer.style.display = 'block';
                    insuranceStartDateInput.setAttribute('required', 'required');
                    insuranceEndDateInput.setAttribute('required', 'required');
                } else {
                    insuranceDetailsContainer.style.display = 'none';
                    insuranceStartDateInput.removeAttribute('required');
                    insuranceEndDateInput.removeAttribute('required');
                    insuranceTypeInput.value = '';
                    insuranceStartDateInput.value = '';
                    insuranceEndDateInput.value = '';
                    // Reset card selection
                    document.querySelectorAll('.hp-insurance-card').forEach(card => {
                        card.style.borderColor = '#e0e0e0';
                        card.querySelector('.hp-insurance-check').style.display = 'none';
                        card.querySelector('h4').style.color = '#495057';
                    });
                }
            });
        });

        // Insurance Plan Card Selection
        const insuranceCards = document.querySelectorAll('.hp-insurance-card');
        insuranceCards.forEach(card => {
            card.addEventListener('click', function() {
                // Reset all cards
                insuranceCards.forEach(c => {
                    c.style.borderColor = '#e0e0e0';
                    c.querySelector('.hp-insurance-check').style.display = 'none';
                    c.querySelector('h4').style.color = '#495057';
                });
                // Select this card
                this.style.borderColor = '#7c3aed';
                this.querySelector('.hp-insurance-check').style.display = 'flex';
                this.querySelector('h4').style.color = '#7c3aed';
                // Update hidden input
                insuranceTypeInput.value = this.getAttribute('data-plan');
            });
        });

        // Initialize Flatpickr for insurance dates
        const insuranceStartFlatpickr = flatpickr(insuranceStartDateInput, {
            locale: 'en',
            dateFormat: 'Y-m-d',
            minDate: 'today',
            onChange: function(selectedDates) {
                if (selectedDates[0]) {
                    insuranceEndFlatpickr.set('minDate', selectedDates[0]);
                }
            }
        });

        const insuranceEndFlatpickr = flatpickr(insuranceEndDateInput, {
            locale: 'en',
            dateFormat: 'Y-m-d',
            minDate: 'today'
        });

        // Passport file upload handler
        const passportFrontInput = document.getElementById('passportFrontInput');

        passportFrontInput.addEventListener('change', function() {
            handlePassportUpload(this, 'front');
        });
    });

    // Handle passport file upload
    function handlePassportUpload(input, side) {
        const file = input.files[0];
        if (!file) return;

        const box = document.getElementById(`passport${side.charAt(0).toUpperCase() + side.slice(1)}Box`);
        const preview = document.getElementById(`passport${side.charAt(0).toUpperCase() + side.slice(1)}Preview`);
        const image = document.getElementById(`passport${side.charAt(0).toUpperCase() + side.slice(1)}Image`);
        const name = document.getElementById(`passport${side.charAt(0).toUpperCase() + side.slice(1)}Name`);

        // Hide upload box, show preview
        box.style.display = 'none';
        preview.style.display = 'flex';

        // Set file name
        name.textContent = file.name;

        // Check if it's an image or PDF
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                image.src = e.target.result;
                image.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else if (file.type === 'application/pdf') {
            // Show PDF icon instead of image
            image.style.display = 'none';
            const pdfIcon = document.createElement('div');
            pdfIcon.className = 'hp-file-pdf-icon';
            pdfIcon.textContent = 'PDF';
            pdfIcon.id = `passport${side.charAt(0).toUpperCase() + side.slice(1)}PdfIcon`;
            preview.insertBefore(pdfIcon, preview.firstChild);
        }

        // Reinitialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    // Remove passport file
    function removePassportFile(side) {
        const input = document.getElementById(`passport${side.charAt(0).toUpperCase() + side.slice(1)}Input`);
        const box = document.getElementById(`passport${side.charAt(0).toUpperCase() + side.slice(1)}Box`);
        const preview = document.getElementById(`passport${side.charAt(0).toUpperCase() + side.slice(1)}Preview`);
        const image = document.getElementById(`passport${side.charAt(0).toUpperCase() + side.slice(1)}Image`);
        const pdfIcon = document.getElementById(`passport${side.charAt(0).toUpperCase() + side.slice(1)}PdfIcon`);

        // Clear input
        input.value = '';

        // Show upload box, hide preview
        box.style.display = 'flex';
        preview.style.display = 'none';

        // Reset image
        image.src = '';
        image.style.display = 'block';

        // Remove PDF icon if exists
        if (pdfIcon) {
            pdfIcon.remove();
        }

        // Reinitialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    // Parental Consent Form upload handler
    const parentalConsentInput = document.getElementById('parentalConsentInput');
    if (parentalConsentInput) {
        parentalConsentInput.addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;

            const box = document.getElementById('parentalConsentBox');
            const preview = document.getElementById('parentalConsentPreview');
            const icon = document.getElementById('parentalConsentIcon');
            const name = document.getElementById('parentalConsentName');

            // Hide upload box, show preview
            box.style.display = 'none';
            preview.style.display = 'flex';

            // Set file name
            name.textContent = file.name;

            // Set icon based on file type
            if (file.type === 'application/pdf') {
                icon.textContent = 'PDF';
                icon.style.background = 'linear-gradient(135deg, #e53935 0%, #c62828 100%)';
            } else if (file.type.includes('word') || file.name.endsWith('.doc') || file.name.endsWith('.docx')) {
                icon.textContent = 'DOC';
                icon.style.background = 'linear-gradient(135deg, #1976d2 0%, #1565c0 100%)';
            } else if (file.type.startsWith('image/')) {
                icon.textContent = 'IMG';
                icon.style.background = 'linear-gradient(135deg, #43a047 0%, #388e3c 100%)';
            }

            // Reinitialize Lucide icons
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    }

    // Remove parental consent file
    function removeConsentFile() {
        const input = document.getElementById('parentalConsentInput');
        const box = document.getElementById('parentalConsentBox');
        const preview = document.getElementById('parentalConsentPreview');
        const icon = document.getElementById('parentalConsentIcon');

        // Clear input
        input.value = '';

        // Show upload box, hide preview
        box.style.display = 'flex';
        preview.style.display = 'none';

        // Reset icon
        icon.textContent = 'DOC';
        icon.style.background = 'linear-gradient(135deg, #9575cd 0%, #7e57c2 100%)';

        // Reinitialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    // Add Course Function
    let courseCount = 0;
    function addCourse() {
        courseCount++;
        const container = document.getElementById('coursesContainer');

        const courseHTML = `
            <div class="hp-additional-course" id="course-${courseCount}">
                <button type="button" class="hp-remove-course-btn" onclick="removeCourse(${courseCount})">×</button>
                <h4 style="margin-bottom: 20px; color: var(--primary-purple); font-size: 18px;">Additional Course ${courseCount}</h4>

                <div class="hp-enrollment-form-group">
                    <label>School *</label>
                    <select name="school_${courseCount}" required>
                        <option value="">Select a school</option>
                        <option value="hablandis-beach-rincon">Hablandis-Beach-Rincón de la Victoria</option>
                        <option value="hablandis-mountain-colmenar">Hablandis-Mountain-Colmenar</option>
                        <option value="hablandis-mountain-casabermeja">Hablandis-Mountain-Casabermeja</option>
                        <option value="hablandis-mountain-riogordo">Hablandis-Mountain-Riogordo</option>
                    </select>
                </div>

                <div class="hp-enrollment-form-group">
                    <label>Course Category *</label>
                    <input type="hidden" name="courseType_${courseCount}" id="courseTypeInput_${courseCount}" required>
                    <input type="hidden" name="courseCategory_${courseCount}" id="courseCategoryInput_${courseCount}" required>

                    <!-- Main Category Selector -->
                    <div class="hp-course-options hp-category-selector" id="categorySelector_${courseCount}">
                        <div class="hp-course-option hp-category-option" data-category="pathway" data-course-id="${courseCount}">University Pathway Program</div>
                        <div class="hp-course-option hp-category-option" data-category="long-term" data-course-id="${courseCount}">Long-term Courses</div>
                        <div class="hp-course-option hp-category-option" data-category="short-term" data-course-id="${courseCount}">Short-term Courses</div>
                        <div class="hp-course-option hp-category-option" data-category="summer" data-course-id="${courseCount}">Summer Programs</div>
                        <div class="hp-course-option hp-category-option" data-category="teacher" data-course-id="${courseCount}">Teacher Training</div>
                        <div class="hp-course-option hp-category-option" data-category="immersion" data-course-id="${courseCount}">Language Immersion Program</div>
                    </div>

                    <!-- University Pathway Program Details -->
                    <div class="hp-course-details" id="pathwayDetails_${courseCount}" style="display: none;">
                        <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                            <label>Program Type *</label>
                            <select name="pathwayProgram_${courseCount}" id="pathwayProgramSelect_${courseCount}" class="hp-course-select">
                                <option value="">Select program</option>
                                <option value="spanish-bachelors">Spanish + Bachelor's (total 4 years)</option>
                                <option value="spanish-mba">Spanish + MBA</option>
                            </select>
                        </div>
                        <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                            <label>Preferred Start Date *</label>
                            <input type="text" name="pathwayStartDate_${courseCount}" id="pathwayStartDateInput_${courseCount}" placeholder="Select date" class="hp-course-select pathway-date">
                        </div>
                    </div>

                    <!-- Long-term Courses Details -->
                    <div class="hp-course-details" id="longTermDetails_${courseCount}" style="display: none;">
                        <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                            <label>Course Duration *</label>
                            <select name="longTermDuration_${courseCount}" id="longTermDurationSelect_${courseCount}" class="hp-course-select">
                                <option value="">Select duration</option>
                                <option value="12-weeks">12 weeks</option>
                                <option value="24-weeks">24 weeks</option>
                                <option value="36-weeks">36 weeks</option>
                                <option value="48-weeks">48 weeks</option>
                            </select>
                        </div>
                        <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                            <label>Course Start Date (Monday) *</label>
                            <input type="date" name="startDate_${courseCount}" id="startDateInput_${courseCount}" class="monday-only-date" lang="en">
                        </div>
                    </div>

                    <!-- Short-term Courses Details -->
                    <div class="hp-course-details" id="shortTermDetails_${courseCount}" style="display: none;">
                        <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                            <label>Select Course *</label>
                            <select name="shortTermCourse_${courseCount}" id="shortTermCourseSelect_${courseCount}" class="hp-course-select">
                                <option value="">Select a course</option>
                                <option value="intensive">Intensive Spanish Course (20 lessons/week)</option>
                                <option value="semi-intensive">Semi-intensive Spanish Course (10 lessons/week)</option>
                                <option value="combined-20-5">Combined Spanish Course (25 lessons/week: 20 group + 5 private)</option>
                                <option value="combined-20-10">Combined Spanish Course (30 lessons/week: 20 group + 10 private)</option>
                                <option value="dele">DELE Exam Preparation Course (20 lessons/week - Group classes if group is formed)</option>
                                <option value="private-1">Private Spanish Course (1 Student)</option>
                                <option value="private-2">Private Spanish Course (2 Students)</option>
                            </select>
                        </div>
                        <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                            <label>Number of Weeks *</label>
                            <input type="number" name="weeks_${courseCount}" min="1" max="11">
                        </div>
                        <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                            <label>Course Start Date (Monday) *</label>
                            <input type="date" name="shortTermStartDate_${courseCount}" id="shortTermStartDateInput_${courseCount}" class="monday-only-date" lang="en">
                        </div>
                    </div>

                    <!-- Summer Programs Details -->
                    <div class="hp-course-details" id="summerDetails_${courseCount}" style="display: none;">
                        <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                            <label>Select Program *</label>
                            <select name="summerCourse_${courseCount}" id="summerCourseSelect_${courseCount}" class="hp-course-select">
                                <option value="">Select a program</option>
                                <option value="summer-family-day">Summer Family Course - Day Camp</option>
                                <option value="international-day">International Summer Camp - Day Camp</option>
                                <option value="international-full">International Summer Camp - Full Day Camp</option>
                            </select>
                        </div>
                    </div>

                    <!-- Teacher Training Details -->
                    <div class="hp-course-details" id="teacherDetails_${courseCount}" style="display: none;">
                        <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                            <label>Select Course *</label>
                            <select name="teacherCourse_${courseCount}" id="teacherCourseSelect_${courseCount}" class="hp-course-select">
                                <option value="">Select a course</option>
                                <option value="teacher-advanced-ele">Advanced ELE Teacher Training (1 week - 20 sessions on didactic innovation with top ELE specialists)</option>
                                <option value="teacher-erasmus-staff">Erasmus+ Courses for Staff (7 courses for language teachers, offered in Spanish or English)</option>
                                <option value="teacher-trinity-onsite-earlybird">Onsite Trinity CertTESOL Course (4 weeks) - Early Bird (paid 2 months in advance)</option>
                                <option value="teacher-trinity-onsite-standard">Onsite Trinity CertTESOL Course (4 weeks) - Standard course fee</option>
                                <option value="teacher-trinity-online-earlybird">Online Trinity CertTESOL Course (10 weeks) - Early Bird (paid 2 months in advance)</option>
                                <option value="teacher-trinity-online-standard">Online Trinity CertTESOL Course (10 weeks) - Standard course fee</option>
                            </select>
                        </div>
                    </div>

                    <!-- Language Immersion Details -->
                    <div class="hp-course-details" id="immersionDetails_${courseCount}" style="display: none;">
                        <div class="hp-enrollment-form-group" style="margin-top: 20px;">
                            <label>Select Program *</label>
                            <select name="immersionCourse_${courseCount}" id="immersionCourseSelect_${courseCount}" class="hp-course-select">
                                <option value="">Select a program</option>
                                <option value="study-trips-homestay">Student groups from 5 nights with homestay</option>
                            </select>
                        </div>
                        <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                            <label>Number of Nights *</label>
                            <input type="number" name="immersionNights_${courseCount}" min="5">
                        </div>
                        <div class="hp-enrollment-form-group" style="margin-top: 15px;">
                            <label>Course Start Date *</label>
                            <input type="date" name="studentGroupStartDate_${courseCount}" id="studentGroupStartDateInput_${courseCount}" class="any-day-date" lang="en">
                        </div>
                    </div>
                </div>
            </div>
        `;

        container.insertAdjacentHTML('beforeend', courseHTML);

        // Initialize category selection for new course
        initializeCourseCategory(courseCount);
    }

    function initializeCourseCategory(courseId) {
        const categoryOptions = document.querySelectorAll(`#categorySelector_${courseId} .hp-category-option`);
        const courseTypeInput = document.getElementById(`courseTypeInput_${courseId}`);
        const courseCategoryInput = document.getElementById(`courseCategoryInput_${courseId}`);

        const categoryMap = {
            'pathway': document.getElementById(`pathwayDetails_${courseId}`),
            'long-term': document.getElementById(`longTermDetails_${courseId}`),
            'short-term': document.getElementById(`shortTermDetails_${courseId}`),
            'summer': document.getElementById(`summerDetails_${courseId}`),
            'teacher': document.getElementById(`teacherDetails_${courseId}`),
            'immersion': document.getElementById(`immersionDetails_${courseId}`)
        };

        const selectMap = {
            'pathway': document.getElementById(`pathwayProgramSelect_${courseId}`),
            'long-term': document.getElementById(`longTermDurationSelect_${courseId}`),
            'short-term': document.getElementById(`shortTermCourseSelect_${courseId}`),
            'summer': document.getElementById(`summerCourseSelect_${courseId}`),
            'teacher': document.getElementById(`teacherCourseSelect_${courseId}`),
            'immersion': document.getElementById(`immersionCourseSelect_${courseId}`)
        };

        // Initialize Flatpickr for pathway start date in additional courses
        const pathwayDateInput = document.getElementById(`pathwayStartDateInput_${courseId}`);
        if (pathwayDateInput) {
            flatpickr(pathwayDateInput, {
                locale: 'en',
                dateFormat: 'Y-m-d',
                minDate: 'today',
                maxDate: new Date(new Date().getFullYear() + 5, 11, 31)
            });
        }

        // Fixed course types for categories that don't have a course selector
        const fixedCourseTypes = {
            'pathway': 'pathway-program',
            'long-term': 'long-term-intensive'
        };

        // Hide all details
        function hideAllDetails() {
            Object.values(categoryMap).forEach(details => {
                if (details) details.style.display = 'none';
            });
            Object.values(selectMap).forEach(select => {
                if (select) select.value = '';
            });
            courseTypeInput.value = '';
        }

        // Category click handler
        categoryOptions.forEach(option => {
            option.addEventListener('click', function() {
                categoryOptions.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');

                const category = this.getAttribute('data-category');
                courseCategoryInput.value = category;

                hideAllDetails();

                if (categoryMap[category]) {
                    categoryMap[category].style.display = 'block';
                }

                // Set fixed course type for pathway and long-term
                if (fixedCourseTypes[category]) {
                    courseTypeInput.value = fixedCourseTypes[category];
                }

                // Initialize date pickers when showing details
                if (category === 'long-term') {
                    setupMondayDatePicker(`startDateInput_${courseId}`);
                } else if (category === 'short-term') {
                    setupMondayDatePicker(`shortTermStartDateInput_${courseId}`);
                } else if (category === 'immersion') {
                    setupAnyDayDatePicker(`studentGroupStartDateInput_${courseId}`);
                }
            });
        });

        // Course select change handlers (only for categories with course selectors)
        ['short-term', 'summer', 'teacher', 'immersion'].forEach(cat => {
            const select = selectMap[cat];
            if (select) {
                select.addEventListener('change', function() {
                    courseTypeInput.value = this.value;
                });
            }
        });
    }

    function removeCourse(courseId) {
        const courseElement = document.getElementById(`course-${courseId}`);
        if (courseElement) {
            courseElement.remove();
        }
    }

    function setupMondayDatePicker(elementId) {
        const dateInput = document.getElementById(elementId);
        if (dateInput) {
            // Calculate next Monday
            const today = new Date();
            const currentDay = today.getDay();
            const daysUntilMonday = currentDay === 0 ? 1 : (8 - currentDay) % 7;
            const nextMonday = new Date(today);
            nextMonday.setDate(today.getDate() + daysUntilMonday);

            flatpickr(dateInput, {
                locale: 'en',
                dateFormat: 'Y-m-d',
                minDate: nextMonday,
                maxDate: new Date(today.getFullYear() + 5, 11, 31),
                showMonths: 1,
                monthSelectorType: 'dropdown',
                disable: [
                    function(date) {
                        // Disable all days except Mondays (1 = Monday)
                        return date.getDay() !== 1;
                    }
                ],
                onReady: function(selectedDates, dateStr, instance) {
                    // Create year dropdown
                    const yearElement = instance.currentYearElement;
                    if (yearElement) {
                        const currentYear = today.getFullYear();
                        const yearSelect = document.createElement('select');
                        yearSelect.className = 'flatpickr-year-select';

                        // Add years from current to +5 years
                        for (let year = currentYear; year <= currentYear + 5; year++) {
                            const option = document.createElement('option');
                            option.value = year;
                            option.textContent = year;
                            yearSelect.appendChild(option);
                        }

                        yearSelect.value = instance.currentYear;

                        yearSelect.addEventListener('change', function() {
                            instance.changeYear(parseInt(this.value));
                        });

                        yearElement.parentNode.replaceChild(yearSelect, yearElement);
                    }
                },
                onChange: function(selectedDates, dateStr, instance) {
                    dateInput.style.borderColor = '#e0e0e0';
                }
            });
        }
    }

    function setupAnyDayDatePicker(elementId) {
        const dateInput = document.getElementById(elementId);
        if (dateInput) {
            const today = new Date();

            flatpickr(dateInput, {
                locale: 'en',
                dateFormat: 'Y-m-d',
                minDate: 'today',
                maxDate: new Date(today.getFullYear() + 5, 11, 31),
                showMonths: 1,
                monthSelectorType: 'dropdown',
                // NO disable array - allows any day
                onReady: function(selectedDates, dateStr, instance) {
                    // Create year dropdown
                    const yearElement = instance.currentYearElement;
                    if (yearElement) {
                        const currentYear = today.getFullYear();
                        const yearSelect = document.createElement('select');
                        yearSelect.className = 'flatpickr-year-select';

                        // Add years from current to +5 years
                        for (let year = currentYear; year <= currentYear + 5; year++) {
                            const option = document.createElement('option');
                            option.value = year;
                            option.textContent = year;
                            yearSelect.appendChild(option);
                        }

                        yearSelect.value = instance.currentYear;

                        yearSelect.addEventListener('change', function() {
                            instance.changeYear(parseInt(this.value));
                        });

                        yearElement.parentNode.replaceChild(yearSelect, yearElement);
                    }
                },
                onChange: function(selectedDates, dateStr, instance) {
                    dateInput.style.borderColor = '#e0e0e0';
                }
            });
        }
    }

    function changeStep(direction) {
        const newStep = currentStep + direction;

        if (newStep < 1 || newStep > totalSteps) {
            return;
        }

        // Validate current step before moving forward
        if (direction > 0) {
            const currentStepEl = document.querySelector(`.hp-enrollment-form-step[data-step="${currentStep}"]`);
            const inputs = currentStepEl.querySelectorAll('input[required], select[required], textarea[required]');
            let isValid = true;

            inputs.forEach(input => {
                if (!input.value) {
                    isValid = false;
                    input.style.borderColor = '#ff4444';
                } else {
                    input.style.borderColor = '#e0e0e0';
                }
            });

            if (!isValid) {
                alert('Please fill in all required fields before continuing.');
                return;
            }
        }

        currentStep = newStep;
        showStep(currentStep);
    }

    // Price Configuration - All prices in EUR
    const PRICES = {
        // SHORT-TERM SPANISH COURSES (prices per week: 1, 2, 3, 4 weeks + extra week after 4)
        shortTermCourses: {
            'semi-intensive': {
                name: 'Semi-intensive (10 lessons/week)',
                prices: { 1: 151, 2: 280, 3: 390, 4: 512 },
                extraWeek: 128
            },
            'intensive': {
                name: 'Intensive (20 lessons/week)',
                prices: { 1: 205, 2: 410, 3: 585, 4: 740 },
                extraWeek: 185
            },
            'combined-20-5': {
                name: 'Combined (20 group + 5 private)',
                prices: { 1: 405, 2: 610, 3: 785, 4: 940 },
                extraWeek: 385
            },
            'combined-20-10': {
                name: 'Combined (20 group + 10 private)',
                prices: { 1: 605, 2: 810, 3: 985, 4: 1140 },
                extraWeek: 485
            },
            'dele': {
                name: 'DELE Exam Preparation',
                prices: { 1: 521, 2: 1020, 3: 1500, 4: 1992 },
                extraWeek: 498
            },
            'private-1': {
                name: 'Private (1 student)',
                pricePerLesson: 45,
                packages: { 1: 45, 5: 200, 10: 370, 20: 700 }
            },
            'private-2': {
                name: 'Private (2 students)',
                pricePerLesson: 60,
                packages: { 1: 60, 5: 280, 10: 500, 20: 950 }
            }
        },

        // LONG-TERM SPANISH COURSES (prices by weeks: 12, 16, 24, 32, 40, 48)
        longTermCourses: {
            'semi-intensive': {
                name: 'Semi-intensive Long-term (10 lessons/week)',
                prices: { 12: 1452, 16: 1840, 24: 2688, 32: 3680, 40: 4320, 48: 5136 }
            },
            'intensive': {
                name: 'Intensive Long-term (20 lessons/week)',
                prices: { 12: 1980, 16: 2640, 24: 3720, 32: 4800, 40: 5800, 48: 6720 }
            }
        },

        // SUMMER PROGRAMS
        summerPrograms: {
            'summer-family-day': {
                name: 'Summer Family Course (Day Camp)',
                prices: { 1: 310, 2: 610, 3: 855, 4: 1092, 5: 1350, 6: 1620 },
                description: 'Intensive Spanish + International Summer Camp (Day)'
            },
            'summer-family-full': {
                name: 'Summer Family Course (Full Day Camp)',
                prices: { 1: 405, 2: 770, 3: 1050, 4: 1320, 5: 1635, 6: 1962 },
                description: 'Intensive Spanish + International Summer Camp (Full Day)'
            },
            'international-day': {
                name: 'International Summer Camp (Day Camp)',
                prices: { 1: 105, 2: 200, 3: 270, 4: 352, 5: 425, 6: 510 },
                description: '10 language lessons/week + 15h activities (ages 4-16)'
            },
            'international-full': {
                name: 'International Summer Camp (Full Day Camp)',
                prices: { 1: 200, 2: 360, 3: 465, 4: 580, 5: 710, 6: 852 },
                description: '15 language lessons/week + 20h activities (ages 12-16)'
            }
        },

        // TEACHER TRAINING
        teacherTraining: {
            'teacher-advanced-ele': { name: 'Advanced ELE Teacher Training', price: 400 },
            'teacher-erasmus-staff': { name: 'Erasmus+ Courses for Staff', price: 400 },
            'teacher-trinity-onsite-earlybird': { name: 'Onsite Trinity CertTESOL (Early Bird)', price: 1499 },
            'teacher-trinity-onsite-standard': { name: 'Onsite Trinity CertTESOL (Standard)', price: 1699 },
            'teacher-trinity-online-earlybird': { name: 'Online Trinity CertTESOL (Early Bird)', price: 1299 },
            'teacher-trinity-online-standard': { name: 'Online Trinity CertTESOL (Standard)', price: 1499 }
        },

        // UNIVERSITY PATHWAY PROGRAMS (EADE) - Annual fees
        pathway: {
            'bachelors-business': { name: "Bachelor's in Business Administration", annualFee: 7640 },
            'bachelors-physical-education': { name: "Bachelor's in Physical Education", annualFee: 8300 },
            'bachelors-design': { name: "Bachelor's in Design", annualFee: 6160 },
            'bachelors-video-game': { name: "Bachelor's in Video Game Design", annualFee: 7640 },
            'bachelors-vfx': { name: "Bachelor's in Animation and VFX", annualFee: 7640 },
            'masters-mba': { name: "MBA Master's", annualFee: 7640 },
            'masters-visual-communication': { name: "Master's in Visual Communication", annualFee: 7640 },
            'masters-physical-education': { name: "Master's in Physical Education", annualFee: 8300 },
            'spanish-bachelors': { name: "Spanish + Bachelor's Program", annualFee: 7640, visaLetter: 6000 },
            'spanish-mba': { name: "Spanish + MBA Program", annualFee: 7640, visaLetter: 6000 }
        },

        // LANGUAGE IMMERSION / STUDY TRIPS
        studyTrips: {
            'study-trips-homestay': {
                name: 'Student Groups with Homestay',
                pricePerNight: 77, // Starting from €460 for 6 nights ≈ €77/night
                minNights: 5,
                description: 'Includes Spanish course, accommodation, transfers, activities'
            }
        },

        // ACCOMMODATION - Shared Apartments
        sharedApartments: {
            'shared-single': {
                name: 'Shared Apartment - Single Room',
                prices: { 1: 590, 2: 1180, 3: 1770, 4: 2360 },
                extraWeek: 650,
                extraNight: 35
            },
            'shared-double': {
                name: 'Shared Apartment - Double Room',
                prices: { 1: 340, 2: 680, 3: 1020, 4: 1360 },
                extraWeek: 340,
                extraNight: 30
            }
        },

        // ACCOMMODATION - Private Apartments
        privateApartments: {
            'private-1person': {
                name: 'Private Apartment - 1 Person',
                prices: { 1: 1240, 2: 2480, 3: 3720, 4: 4960 },
                extraWeek: 1240,
                extraNight: 65
            },
            'private-2people': {
                name: 'Private Apartment - 2 People',
                prices: { 1: 695, 2: 1390, 3: 2085, 4: 2780 },
                extraWeek: 695,
                extraNight: 40
            }
        },

        // ACCOMMODATION - Spanish Family (Homestay)
        homestay: {
            'family-single-breakfast': {
                name: 'Spanish Family - Single Room (Breakfast)',
                prices: { 1: 280, 2: 490, 3: 760, 4: 950 },
                extraWeek: 290,
                extraNight: 40
            },
            'family-single-halfboard': {
                name: 'Spanish Family - Single Room (Half Board)',
                prices: { 1: 376, 2: 586, 3: 856, 4: 1046 },
                extraWeek: 386,
                extraNight: 40
            },
            'family-single-fullboard': {
                name: 'Spanish Family - Single Room (Full Board)',
                prices: { 1: 420, 2: 630, 3: 900, 4: 1090 },
                extraWeek: 430,
                extraNight: 40
            },
            'family-double-breakfast': {
                name: 'Spanish Family - Double Room (Breakfast)',
                prices: { 1: 240, 2: 440, 3: 630, 4: 820 },
                extraWeek: 240,
                extraNight: 30
            },
            'family-double-halfboard': {
                name: 'Spanish Family - Double Room (Half Board)',
                prices: { 1: 336, 2: 536, 3: 726, 4: 916 },
                extraWeek: 336,
                extraNight: 30
            },
            'family-double-fullboard': {
                name: 'Spanish Family - Double Room (Full Board)',
                prices: { 1: 380, 2: 580, 3: 770, 4: 960 },
                extraWeek: 380,
                extraNight: 30
            }
        },

        // Accommodation extras
        housingExtras: {
            'half-board': { name: 'Half Board Supplement', pricePerWeek: 96 },
            'full-board': { name: 'Full Board Supplement', pricePerWeek: 140 },
            'dietary-restrictions': { name: 'Dietary Restrictions Supplement', pricePerWeek: 35 },
            'high-season': { name: 'High Season Supplement', pricePerNight: 10 }
        },

        // TRANSFERS
        transfer: {
            'airport-arrival': { name: 'Malaga Airport - One Way (Arrival)', price: 60 },
            'airport-departure': { name: 'Malaga Airport - One Way (Departure)', price: 60 },
            'airport-both': { name: 'Malaga Airport - Round Trip', price: 120 },
            'train-arrival': { name: 'Malaga Train Station - One Way (Arrival)', price: 35 },
            'train-departure': { name: 'Malaga Train Station - One Way (Departure)', price: 35 },
            'train-both': { name: 'Malaga Train Station - Round Trip', price: 70 },
            'night-supplement': { name: 'Night Supplement (00:30-7:00 AM)', pricePerTrip: 15 }
        },

        // INSURANCE (Swisscare) - per day
        insurance: {
            'standard': { name: 'Standard', pricePerDay: 1.00, maxCoverage: 50000 },
            'comfort': { name: 'Comfort', pricePerDay: 1.50, maxCoverage: 150000 },
            'premium': { name: 'Premium', pricePerDay: 2.00, maxCoverage: 500000 }
        },

        // Registration/Enrollment Fee
        enrollmentFee: 45, // Does not apply to private lessons

        // High Season dates (for accommodation supplement)
        highSeasonDates: {
            summerStart: { month: 5, day: 1 },  // June 1 (month is 0-indexed: 5 = June)
            summerEnd: { month: 8, day: 30 },   // September 30
            // Easter dates vary each year - calculated dynamically
        }
    };

    // Helper function to check if date is in high season
    function isHighSeason(date) {
        if (!date) return false;
        const d = new Date(date);
        const month = d.getMonth();
        const day = d.getDate();

        // Summer high season: June 1 - September 30
        if (month >= 5 && month <= 8) {
            return true;
        }

        // Easter approximation (March-April) - simplified check
        // In a real implementation, you'd calculate Easter date for the specific year
        if ((month === 2 || month === 3) && day >= 15 && day <= 30) {
            // Rough Easter window - March 15 to April 30
            return true;
        }

        return false;
    }

    // Helper function to calculate number of nights between two dates
    function calculateNights(startDate, endDate) {
        if (!startDate || !endDate) return 0;
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = Math.abs(end - start);
        return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    }

    // Helper function to count high season nights
    function countHighSeasonNights(startDate, endDate) {
        if (!startDate || !endDate) return 0;
        let count = 0;
        const start = new Date(startDate);
        const end = new Date(endDate);

        for (let d = new Date(start); d < end; d.setDate(d.getDate() + 1)) {
            if (isHighSeason(d)) {
                count++;
            }
        }
        return count;
    }

    function generateSummary() {
        const form = document.getElementById('enrollmentForm');
        const formData = new FormData(form);

        // Calculate prices
        let courseSubtotal = 0;
        let extrasTotal = 0;
        let lineItems = [];
        let extrasItems = [];
        let applyEnrollmentFee = true;

        // Get selected category
        const selectedCategory = document.querySelector('.hp-category-option.selected');
        const category = selectedCategory ? selectedCategory.getAttribute('data-category') : null;

        // Get school info
        const schoolSelect = document.querySelector('select[name="school"]');
        const schoolText = schoolSelect ? schoolSelect.options[schoolSelect.selectedIndex].text : 'Not specified';

        // Course name for display
        let courseName = 'Not specified';
        let courseWeeks = 0;
        let startDate = '';

        // Calculate course price based on category
        if (category === 'short-term') {
            const shortTermCourse = formData.get('shortTermCourse');
            const weeks = parseInt(formData.get('weeks')) || 0;
            courseWeeks = weeks;
            startDate = formData.get('shortTermStartDate') || '';

            if (shortTermCourse && PRICES.shortTermCourses[shortTermCourse]) {
                const courseData = PRICES.shortTermCourses[shortTermCourse];
                courseName = courseData.name;

                // Check if it's a private course
                if (shortTermCourse.startsWith('private-')) {
                    // Private courses - price per lesson, skip enrollment fee
                    applyEnrollmentFee = false;
                    const lessons = weeks; // For private, "weeks" is actually number of lessons
                    if (courseData.packages && courseData.packages[lessons]) {
                        courseSubtotal = courseData.packages[lessons];
                    } else {
                        courseSubtotal = courseData.pricePerLesson * lessons;
                    }
                    lineItems.push({
                        name: `${courseName} (${lessons} lessons)`,
                        price: courseSubtotal,
                        details: schoolText
                    });
                } else {
                    // Regular courses - price by weeks
                    if (weeks <= 4 && courseData.prices[weeks]) {
                        courseSubtotal = courseData.prices[weeks];
                    } else if (weeks > 4 && courseData.prices[4] && courseData.extraWeek) {
                        // 4 weeks base + extra weeks
                        courseSubtotal = courseData.prices[4] + (courseData.extraWeek * (weeks - 4));
                    }
                    lineItems.push({
                        name: `${courseName} (${weeks} weeks)`,
                        price: courseSubtotal,
                        details: schoolText
                    });
                }
            }
        } else if (category === 'long-term') {
            const longTermDuration = formData.get('longTermDuration');
            startDate = formData.get('startDate') || '';

            if (longTermDuration) {
                const weeksMatch = longTermDuration.match(/(\d+)/);
                const weeks = weeksMatch ? parseInt(weeksMatch[1]) : 0;
                courseWeeks = weeks;

                // Default to intensive for long-term
                const courseType = 'intensive';
                if (PRICES.longTermCourses[courseType] && PRICES.longTermCourses[courseType].prices[weeks]) {
                    courseSubtotal = PRICES.longTermCourses[courseType].prices[weeks];
                    courseName = PRICES.longTermCourses[courseType].name;
                    lineItems.push({
                        name: `${courseName} (${weeks} weeks)`,
                        price: courseSubtotal,
                        details: schoolText
                    });
                }
            }
        } else if (category === 'summer') {
            const summerCourse = formData.get('summerCourse');
            const summerWeeks = parseInt(formData.get('summerWeeks')) || 1;
            courseWeeks = summerWeeks;

            if (summerCourse && PRICES.summerPrograms[summerCourse]) {
                const programData = PRICES.summerPrograms[summerCourse];
                courseName = programData.name;

                if (programData.prices[summerWeeks]) {
                    courseSubtotal = programData.prices[summerWeeks];
                }
                lineItems.push({
                    name: `${courseName} (${summerWeeks} weeks)`,
                    price: courseSubtotal,
                    details: programData.description || ''
                });
            }
        } else if (category === 'teacher') {
            const teacherCourse = formData.get('teacherCourse');

            if (teacherCourse && PRICES.teacherTraining[teacherCourse]) {
                const courseData = PRICES.teacherTraining[teacherCourse];
                courseName = courseData.name;
                courseSubtotal = courseData.price;
                lineItems.push({
                    name: courseName,
                    price: courseSubtotal,
                    details: 'Teacher Training Program'
                });
            }
        } else if (category === 'pathway') {
            const pathwayProgram = formData.get('pathwayProgram');
            startDate = formData.get('pathwayStartDate') || '';

            if (pathwayProgram && PRICES.pathway[pathwayProgram]) {
                const programData = PRICES.pathway[pathwayProgram];
                courseName = programData.name;

                // Initial payment of €6,000 includes: Visa Letter + Spanish Course + EADE Reservation
                // This initial payment covers €2,000 of the annual fee
                const initialPayment = 6000;
                const includedInInitial = 2000;
                const adjustedAnnualFee = programData.annualFee - includedInInitial;

                // Add initial payment (Visa Letter + Spanish Course + EADE Reservation)
                lineItems.push({
                    name: 'Initial Payment',
                    price: initialPayment,
                    details: 'Student Visa Letter + Spanish Course + EADE Reservation'
                });
                courseSubtotal += initialPayment;

                // Add adjusted annual fee (minus the €2,000 included in initial payment)
                lineItems.push({
                    name: `${courseName} (Annual Fee)`,
                    price: adjustedAnnualFee,
                    details: `€${includedInInitial.toLocaleString()} already included in initial payment`
                });
                courseSubtotal += adjustedAnnualFee;
            }
        } else if (category === 'immersion') {
            const immersionNights = parseInt(formData.get('immersionNights')) || 6;
            startDate = formData.get('studentGroupStartDate') || '';

            if (PRICES.studyTrips['study-trips-homestay']) {
                const programData = PRICES.studyTrips['study-trips-homestay'];
                courseName = programData.name;
                courseSubtotal = programData.pricePerNight * immersionNights;
                lineItems.push({
                    name: `${courseName} (${immersionNights} nights)`,
                    price: courseSubtotal,
                    details: programData.description
                });
            }
        }

        // HOUSING CALCULATION
        const housingRequired = formData.get('housingRequired');
        const accommodationType = formData.get('accommodationType');
        const housingType = formData.get('housingType');
        const checkInDate = formData.get('checkInDate');
        const checkOutDate = formData.get('checkOutDate');
        const doubleRoomOccupancy = formData.get('doubleRoomOccupancy');

        let housingName = '';
        let housingPrice = 0;

        if (housingRequired === 'yes' && housingType) {
            // Calculate number of nights/weeks
            const nights = calculateNights(checkInDate, checkOutDate);
            const weeks = Math.ceil(nights / 7) || courseWeeks || 1;

            // Check if it's a double room or 2-people accommodation (prices are per person)
            const isDoubleOccupancy = housingType.includes('double') || housingType.includes('2people');
            const numberOfPeople = isDoubleOccupancy ? 2 : 1;

            // Find the housing in the correct category
            let housingData = null;
            if (accommodationType === 'homestay') {
                housingData = PRICES.homestay[housingType];
            } else if (accommodationType === 'student-apartment') {
                housingData = PRICES.sharedApartments[housingType];
            } else if (accommodationType === 'private-apartment') {
                housingData = PRICES.privateApartments[housingType];
            }

            if (housingData) {
                housingName = housingData.name;

                // Calculate base price per person
                let pricePerPerson = 0;
                if (weeks <= 4 && housingData.prices && housingData.prices[weeks]) {
                    pricePerPerson = housingData.prices[weeks];
                } else if (weeks > 4 && housingData.prices && housingData.prices[4] && housingData.extraWeek) {
                    pricePerPerson = housingData.prices[4] + (housingData.extraWeek * (weeks - 4));
                } else if (housingData.prices) {
                    // Find closest available week
                    const availableWeeks = Object.keys(housingData.prices).map(Number).sort((a, b) => a - b);
                    const closestWeek = availableWeeks.reduce((prev, curr) =>
                        Math.abs(curr - weeks) < Math.abs(prev - weeks) ? curr : prev
                    );
                    pricePerPerson = housingData.prices[closestWeek];
                }

                // Multiply by number of people for double rooms
                housingPrice = pricePerPerson * numberOfPeople;

                // Add high season supplement if applicable (per person per night)
                const highSeasonNights = countHighSeasonNights(checkInDate, checkOutDate);
                if (highSeasonNights > 0) {
                    const highSeasonSupplement = highSeasonNights * PRICES.housingExtras['high-season'].pricePerNight * numberOfPeople;
                    extrasItems.push({
                        name: `High Season Supplement (${highSeasonNights} nights × €10 × ${numberOfPeople} ${numberOfPeople > 1 ? 'people' : 'person'})`,
                        price: highSeasonSupplement
                    });
                    extrasTotal += highSeasonSupplement;
                }

                // Show detailed pricing for double occupancy
                if (isDoubleOccupancy) {
                    extrasItems.push({
                        name: `${housingName} (${weeks} weeks × 2 people)`,
                        price: housingPrice
                    });
                } else {
                    extrasItems.push({
                        name: `${housingName} (${weeks} weeks)`,
                        price: housingPrice
                    });
                }
                extrasTotal += housingPrice;
            }
        }

        // TRANSFER CALCULATION
        const transferRequired = formData.get('transferRequired');
        const transferType = formData.get('transferType');

        if (transferRequired === 'yes' && transferType) {
            let transferKey = '';
            if (transferType === 'arrival') {
                transferKey = 'airport-arrival';
            } else if (transferType === 'departure') {
                transferKey = 'airport-departure';
            } else if (transferType === 'both') {
                transferKey = 'airport-both';
            }

            if (transferKey && PRICES.transfer[transferKey]) {
                const transferData = PRICES.transfer[transferKey];
                extrasItems.push({
                    name: transferData.name,
                    price: transferData.price
                });
                extrasTotal += transferData.price;
            }
        }

        // INSURANCE CALCULATION
        const insuranceRequired = formData.get('insuranceRequired');
        const insuranceType = formData.get('insuranceType');
        const insuranceStartDate = formData.get('insuranceStartDate');
        const insuranceEndDate = formData.get('insuranceEndDate');

        if (insuranceRequired === 'yes' && insuranceType && PRICES.insurance[insuranceType]) {
            let insuranceDays = 30; // Default
            if (insuranceStartDate && insuranceEndDate) {
                insuranceDays = calculateNights(insuranceStartDate, insuranceEndDate) + 1;
            } else if (courseWeeks > 0) {
                insuranceDays = courseWeeks * 7;
            }

            const insuranceData = PRICES.insurance[insuranceType];
            const insurancePrice = insuranceData.pricePerDay * insuranceDays;

            extrasItems.push({
                name: `${insuranceData.name} Insurance (${insuranceDays} days @ €${insuranceData.pricePerDay}/day)`,
                price: insurancePrice
            });
            extrasTotal += insurancePrice;
        }

        // Enrollment fee (€45, not applied to private lessons)
        const enrollmentFee = applyEnrollmentFee ? PRICES.enrollmentFee : 0;

        // Calculate total
        const grandTotal = courseSubtotal + extrasTotal + enrollmentFee;

        // Build Summary HTML
        let summaryHTML = `
        <div class="hp-summary-grid">
            <!-- Left Column: Details -->
            <div>
                <h4 style="color: #7c3aed; margin-bottom: 15px; font-size: 16px; border-bottom: 2px solid #7c3aed; padding-bottom: 8px;">
                    <i data-lucide="user" style="width: 18px; height: 18px; display: inline-block; vertical-align: middle; margin-right: 8px;"></i>
                    User Information
                </h4>
                <p style="margin: 8px 0;"><strong>Name:</strong> ${formData.get('studentFirstName') || ''} ${formData.get('studentLastName') || ''}</p>
                <p style="margin: 8px 0;"><strong>Email:</strong> ${formData.get('studentEmail') || 'Not specified'}</p>
                <p style="margin: 8px 0;"><strong>Date of Birth:</strong> ${formData.get('studentDOB') || 'Not specified'}</p>
                <p style="margin: 8px 0;"><strong>Phone:</strong> ${formData.get('studentPhonePrefix') || ''} ${formData.get('studentPhone') || 'Not specified'}</p>

                <h4 style="color: #7c3aed; margin: 25px 0 15px 0; font-size: 16px; border-bottom: 2px solid #7c3aed; padding-bottom: 8px;">
                    <i data-lucide="book-open" style="width: 18px; height: 18px; display: inline-block; vertical-align: middle; margin-right: 8px;"></i>
                    Course Details
                </h4>
                <p style="margin: 8px 0;"><strong>Category:</strong> ${selectedCategory ? selectedCategory.textContent : 'Not specified'}</p>
                <p style="margin: 8px 0;"><strong>Course:</strong> ${courseName}</p>
                ${startDate ? `<p style="margin: 8px 0;"><strong>Start Date:</strong> ${startDate}</p>` : ''}
                ${courseWeeks > 0 ? `<p style="margin: 8px 0;"><strong>Duration:</strong> ${courseWeeks} weeks</p>` : ''}

                ${housingRequired === 'yes' ? `
                <h4 style="color: #7c3aed; margin: 25px 0 15px 0; font-size: 16px; border-bottom: 2px solid #7c3aed; padding-bottom: 8px;">
                    <i data-lucide="home" style="width: 18px; height: 18px; display: inline-block; vertical-align: middle; margin-right: 8px;"></i>
                    Accommodation
                </h4>
                <p style="margin: 8px 0;"><strong>Type:</strong> ${housingName || 'Not specified'}</p>
                ${checkInDate ? `<p style="margin: 8px 0;"><strong>Check-in:</strong> ${checkInDate}</p>` : ''}
                ${checkOutDate ? `<p style="margin: 8px 0;"><strong>Check-out:</strong> ${checkOutDate}</p>` : ''}
                ` : ''}

                ${transferRequired === 'yes' ? `
                <h4 style="color: #7c3aed; margin: 25px 0 15px 0; font-size: 16px; border-bottom: 2px solid #7c3aed; padding-bottom: 8px;">
                    <i data-lucide="plane" style="width: 18px; height: 18px; display: inline-block; vertical-align: middle; margin-right: 8px;"></i>
                    Airport Transfer
                </h4>
                <p style="margin: 8px 0;"><strong>Type:</strong> ${transferType === 'both' ? 'Round Trip' : transferType === 'arrival' ? 'Arrival Only' : 'Departure Only'}</p>
                ` : ''}

                <h4 style="color: #7c3aed; margin: 25px 0 15px 0; font-size: 16px; border-bottom: 2px solid #7c3aed; padding-bottom: 8px;">
                    <i data-lucide="building-2" style="width: 18px; height: 18px; display: inline-block; vertical-align: middle; margin-right: 8px;"></i>
                    Agency Information
                </h4>
                <p style="margin: 8px 0;"><strong>Agency:</strong> ${formData.get('agencyName') || 'Not specified'}</p>
                <p style="margin: 8px 0;"><strong>Contact:</strong> ${formData.get('agencyContact') || 'Not specified'}</p>
                <p style="margin: 8px 0;"><strong>Email:</strong> ${formData.get('agencyEmail') || 'Not specified'}</p>
            </div>

            <!-- Right Column: Price Summary -->
            <div>
                <div style="background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); border-radius: 16px; padding: 25px; color: white;">
                    <h4 style="margin: 0 0 20px 0; font-size: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                        <i data-lucide="receipt" style="width: 24px; height: 24px;"></i>
                        Program Summary
                    </h4>

                    <!-- Course Items -->
                    <div style="border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 15px; margin-bottom: 15px;">
                        <p style="margin: 0 0 10px 0; font-size: 14px; opacity: 0.8; text-transform: uppercase; letter-spacing: 1px;">Course</p>
                        ${lineItems.length > 0 ? lineItems.map(item => `
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                                <div style="flex: 1; padding-right: 15px;">
                                    <p style="margin: 0; font-weight: 500;">${item.name}</p>
                                    ${item.details ? `<p style="margin: 2px 0 0 0; font-size: 12px; opacity: 0.7;">${item.details}</p>` : ''}
                                </div>
                                <span style="font-weight: 600; font-size: 16px; white-space: nowrap;">€${item.price.toFixed(2)}</span>
                            </div>
                        `).join('') : '<p style="margin: 0; opacity: 0.7;">No course selected</p>'}
                    </div>

                    <!-- Extras -->
                    ${extrasItems.length > 0 ? `
                    <div style="border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 15px; margin-bottom: 15px;">
                        <p style="margin: 0 0 10px 0; font-size: 14px; opacity: 0.8; text-transform: uppercase; letter-spacing: 1px;">Extras</p>
                        ${extrasItems.map(item => `
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <span style="flex: 1; padding-right: 10px;">${item.name}</span>
                                <span style="font-weight: 500; white-space: nowrap;">€${item.price.toFixed(2)}</span>
                            </div>
                        `).join('')}
                    </div>
                    ` : ''}

                    <!-- Enrollment Fee -->
                    ${enrollmentFee > 0 ? `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span>Registration Fee</span>
                        <span style="font-weight: 500;">€${enrollmentFee.toFixed(2)}</span>
                    </div>
                    ` : ''}

                    <!-- Total -->
                    <div style="border-top: 2px solid rgba(255,255,255,0.3); padding-top: 15px; margin-top: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 18px; font-weight: 600;">Total</span>
                            <span style="font-size: 28px; font-weight: 700;">€${grandTotal.toFixed(2)}</span>
                        </div>
                    </div>
                </div>

                <!-- Note -->
                <div style="margin-top: 15px; padding: 15px; background: #fef3c7; border-radius: 10px; border-left: 4px solid #f59e0b;">
                    <p style="margin: 0; font-size: 13px; color: #92400e;">
                        <strong>Note:</strong> Final prices may vary. This is an estimate based on current selections. You will receive a detailed invoice via email.
                    </p>
                </div>
            </div>
        </div>
        `;

        document.getElementById('enrollmentSummary').innerHTML = summaryHTML;

        // Reinitialize Lucide icons
        if (typeof lucide !== 'undefined') {
            setTimeout(() => lucide.createIcons(), 100);
        }
    }

    function submitEnrollmentForm() {
        const form = document.getElementById('enrollmentForm');
        const formData = new FormData(form);

        // Add action and nonce for WordPress AJAX
        formData.append('action', 'hablandis_enrollment_form');
        formData.append('nonce', '<?php echo wp_create_nonce("hablandis_enrollment_nonce"); ?>');

        // Get the summary HTML for the email
        const summaryHTML = document.getElementById('enrollmentSummary').innerHTML;
        formData.append('summaryHTML', summaryHTML);

        // Show loading state
        const submitBtn = document.querySelector('#nextBtn');
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Sending...';
        submitBtn.disabled = true;

        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Enrollment form submitted successfully! A confirmation email has been sent.');
                closeEnrollmentModal();
                form.reset();
                currentStep = 1;
                showStep(1);
            } else {
                alert('There was an error submitting the form. Please try again or contact us directly.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('There was an error submitting the form. Please try again or contact us directly.');
        })
        .finally(() => {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        });
    }

    // Initialize enrollment form
    document.addEventListener('DOMContentLoaded', function() {
        showStep(1);

        // Add event listener to enrollment button
        const enrollmentBtn = document.querySelector('.hp-enrollment-button');
        if (enrollmentBtn) {
            enrollmentBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openEnrollmentModal();
            });
        }

        // Close modal when clicking outside
        document.getElementById('enrollmentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEnrollmentModal();
            }
        });
    });

    // Contact Form Submission
    const contactForm = document.getElementById('contactForm');
    if (contactForm) {
        contactForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const submitButton = this.querySelector('.hp-contact-submit');
            const originalText = submitButton.textContent;
            submitButton.textContent = 'Sending...';
            submitButton.disabled = true;

            const formData = new FormData(this);
            formData.append('action', 'hablandis_contact_form');
            formData.append('nonce', '<?php echo wp_create_nonce("hablandis_contact_nonce"); ?>');

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Thank you for contacting us! We will get back to you soon.');
                    contactForm.reset();
                } else {
                    alert('There was an error sending your message. Please try again or contact us directly at comunicacion@hablandis.com');
                }
            })
            .catch(error => {
                alert('There was an error sending your message. Please try again or contact us directly at comunicacion@hablandis.com');
            })
            .finally(() => {
                submitButton.textContent = originalText;
                submitButton.disabled = false;
            });
        });
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('hablandis_partner_portal', 'hablandis_partner_portal_shortcode');

// Handle Contact Form Submission
function hablandis_contact_form_handler() {
    check_ajax_referer('hablandis_contact_nonce', 'nonce');

    $firstName = sanitize_text_field($_POST['firstName']);
    $lastName = sanitize_text_field($_POST['lastName']);
    $email = sanitize_email($_POST['email']);
    $phone = sanitize_text_field($_POST['phone']);
    $company = sanitize_text_field($_POST['company'] ?? '');
    $message = sanitize_textarea_field($_POST['message']);

    // Save to Neon database
    $agency_id = save_agency_to_neon([
        'name' => $company,
        'contact_name' => $firstName . ' ' . $lastName,
        'email' => $email,
        'phone' => $phone,
        'company' => $company,
        'message' => $message,
        'source' => 'contact_form'
    ]);

    $to = 'comunicacion@hablandis.com';
    $subject = 'New Contact Form Submission - Partner Portal';
    $body = "New contact form submission from the Partner Portal:\n\n";
    $body .= "Name: {$firstName} {$lastName}\n";
    $body .= "Email: {$email}\n";
    $body .= "Phone: {$phone}\n";
    $body .= "Company: {$company}\n";
    $body .= "Message:\n{$message}\n";
    $body .= "\n---\nSaved to database with ID: " . ($agency_id ? $agency_id : 'Error');

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: Partner Portal <noreply@' . $_SERVER['HTTP_HOST'] . '>',
        'Reply-To: ' . $email
    );

    $sent = wp_mail($to, $subject, $body, $headers);

    if ($sent) {
        wp_send_json_success(['agency_id' => $agency_id]);
    } else {
        wp_send_json_error();
    }
}
add_action('wp_ajax_hablandis_contact_form', 'hablandis_contact_form_handler');
add_action('wp_ajax_nopriv_hablandis_contact_form', 'hablandis_contact_form_handler');

// Handle Enrollment Form Submission
function hablandis_enrollment_form_handler() {
    check_ajax_referer('hablandis_enrollment_nonce', 'nonce');

    // User Details
    $studentFirstName = sanitize_text_field($_POST['studentFirstName'] ?? '');
    $studentLastName = sanitize_text_field($_POST['studentLastName'] ?? '');
    $studentEmail = sanitize_email($_POST['studentEmail'] ?? '');
    $studentPhone = sanitize_text_field($_POST['studentPhone'] ?? '');
    $studentPhonePrefix = sanitize_text_field($_POST['studentPhonePrefix'] ?? '');
    $studentDOB = sanitize_text_field($_POST['studentDOB'] ?? '');
    $studentAddress = sanitize_text_field($_POST['studentAddress'] ?? '');
    $studentCity = sanitize_text_field($_POST['studentCity'] ?? '');
    $studentZip = sanitize_text_field($_POST['studentZip'] ?? '');

    // Agency Details
    $agencyName = sanitize_text_field($_POST['agencyName'] ?? '');
    $agencyContact = sanitize_text_field($_POST['agencyContact'] ?? '');
    $agencyEmail = sanitize_email($_POST['agencyEmail'] ?? '');

    // Course Information
    $school = sanitize_text_field($_POST['school'] ?? '');
    $shortTermCourse = sanitize_text_field($_POST['shortTermCourse'] ?? '');
    $longTermDuration = sanitize_text_field($_POST['longTermDuration'] ?? '');
    $summerCourse = sanitize_text_field($_POST['summerCourse'] ?? '');
    $teacherCourse = sanitize_text_field($_POST['teacherCourse'] ?? '');
    $pathwayProgram = sanitize_text_field($_POST['pathwayProgram'] ?? '');
    $immersionNights = sanitize_text_field($_POST['immersionNights'] ?? '');
    $weeks = sanitize_text_field($_POST['weeks'] ?? '');
    $startDate = sanitize_text_field($_POST['startDate'] ?? '');
    $shortTermStartDate = sanitize_text_field($_POST['shortTermStartDate'] ?? '');
    $pathwayStartDate = sanitize_text_field($_POST['pathwayStartDate'] ?? '');

    // Housing
    $housingRequired = sanitize_text_field($_POST['housingRequired'] ?? '');
    $accommodationType = sanitize_text_field($_POST['accommodationType'] ?? '');
    $housingType = sanitize_text_field($_POST['housingType'] ?? '');
    $checkInDate = sanitize_text_field($_POST['checkInDate'] ?? '');
    $checkOutDate = sanitize_text_field($_POST['checkOutDate'] ?? '');
    $housingRequirements = sanitize_textarea_field($_POST['housingRequirements'] ?? '');

    // Transfer
    $transferRequired = sanitize_text_field($_POST['transferRequired'] ?? '');
    $transferType = sanitize_text_field($_POST['transferType'] ?? '');

    // Visa
    $admissionLetterRequired = sanitize_text_field($_POST['admissionLetterRequired'] ?? '');

    // Insurance
    $insuranceRequired = sanitize_text_field($_POST['insuranceRequired'] ?? '');
    $insuranceType = sanitize_text_field($_POST['insuranceType'] ?? '');
    $insuranceStartDate = sanitize_text_field($_POST['insuranceStartDate'] ?? '');
    $insuranceEndDate = sanitize_text_field($_POST['insuranceEndDate'] ?? '');

    // Summary HTML from frontend
    $summaryHTML = wp_kses_post($_POST['summaryHTML'] ?? '');

    // Build email body
    $subject = "New Enrollment Form - {$studentFirstName} {$studentLastName}";

    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            h2 { color: #7c3aed; border-bottom: 2px solid #7c3aed; padding-bottom: 10px; }
            h3 { color: #5b21b6; margin-top: 25px; }
            .section { margin-bottom: 25px; padding: 15px; background: #f8f8f8; border-radius: 8px; }
            .field { margin: 8px 0; }
            .label { font-weight: bold; color: #555; }
            .summary-box { background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); padding: 25px; border-radius: 16px; color: white; margin-top: 30px; }
        </style>
    </head>
    <body>
        <h2>New Enrollment Form Submission</h2>

        <div class='section'>
            <h3>User Information</h3>
            <div class='field'><span class='label'>Name:</span> {$studentFirstName} {$studentLastName}</div>
            <div class='field'><span class='label'>Email:</span> {$studentEmail}</div>
            <div class='field'><span class='label'>Phone:</span> {$studentPhonePrefix} {$studentPhone}</div>
            <div class='field'><span class='label'>Date of Birth:</span> {$studentDOB}</div>
            <div class='field'><span class='label'>Address:</span> {$studentAddress}</div>
            <div class='field'><span class='label'>City:</span> {$studentCity}</div>
            <div class='field'><span class='label'>ZIP/Postal Code:</span> {$studentZip}</div>
        </div>

        <div class='section'>
            <h3>Agency Information</h3>
            <div class='field'><span class='label'>Agency Name:</span> {$agencyName}</div>
            <div class='field'><span class='label'>Contact:</span> {$agencyContact}</div>
            <div class='field'><span class='label'>Email:</span> {$agencyEmail}</div>
        </div>

        <div class='section'>
            <h3>Course Information</h3>
            <div class='field'><span class='label'>School:</span> {$school}</div>";

    if ($shortTermCourse) {
        $body .= "<div class='field'><span class='label'>Short-term Course:</span> {$shortTermCourse}</div>";
        $body .= "<div class='field'><span class='label'>Weeks:</span> {$weeks}</div>";
        $body .= "<div class='field'><span class='label'>Start Date:</span> {$shortTermStartDate}</div>";
    }
    if ($longTermDuration) {
        $body .= "<div class='field'><span class='label'>Long-term Duration:</span> {$longTermDuration}</div>";
        $body .= "<div class='field'><span class='label'>Start Date:</span> {$startDate}</div>";
    }
    if ($summerCourse) {
        $body .= "<div class='field'><span class='label'>Summer Program:</span> {$summerCourse}</div>";
    }
    if ($teacherCourse) {
        $body .= "<div class='field'><span class='label'>Teacher Training:</span> {$teacherCourse}</div>";
    }
    if ($pathwayProgram) {
        $body .= "<div class='field'><span class='label'>Pathway Program:</span> {$pathwayProgram}</div>";
        $body .= "<div class='field'><span class='label'>Preferred Start Date:</span> {$pathwayStartDate}</div>";
    }
    if ($immersionNights) {
        $body .= "<div class='field'><span class='label'>Immersion Program Nights:</span> {$immersionNights}</div>";
    }

    $body .= "
        </div>

        <div class='section'>
            <h3>Housing</h3>
            <div class='field'><span class='label'>Housing Required:</span> {$housingRequired}</div>";

    if ($housingRequired === 'yes') {
        $body .= "
            <div class='field'><span class='label'>Accommodation Type:</span> {$accommodationType}</div>
            <div class='field'><span class='label'>Room Type:</span> {$housingType}</div>
            <div class='field'><span class='label'>Check-in:</span> {$checkInDate}</div>
            <div class='field'><span class='label'>Check-out:</span> {$checkOutDate}</div>
            <div class='field'><span class='label'>Special Requirements:</span> {$housingRequirements}</div>";
    }

    $body .= "
        </div>

        <div class='section'>
            <h3>Transfer</h3>
            <div class='field'><span class='label'>Transfer Required:</span> {$transferRequired}</div>";

    if ($transferRequired === 'yes') {
        $body .= "<div class='field'><span class='label'>Transfer Type:</span> {$transferType}</div>";
    }

    $body .= "
        </div>

        <div class='section'>
            <h3>Visa Information</h3>
            <div class='field'><span class='label'>Admission Letter Required:</span> {$admissionLetterRequired}</div>
        </div>

        <div class='section'>
            <h3>Insurance</h3>
            <div class='field'><span class='label'>Insurance Required:</span> {$insuranceRequired}</div>";

    if ($insuranceRequired === 'yes') {
        $body .= "
            <div class='field'><span class='label'>Insurance Type:</span> {$insuranceType}</div>
            <div class='field'><span class='label'>Start Date:</span> {$insuranceStartDate}</div>
            <div class='field'><span class='label'>End Date:</span> {$insuranceEndDate}</div>";
    }

    $body .= "
        </div>

        <h3>Price Summary</h3>
        <div style='margin-top: 20px;'>
            {$summaryHTML}
        </div>

    </body>
    </html>
    ";

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Hablandis Partner Portal <noreply@' . $_SERVER['HTTP_HOST'] . '>',
        'Reply-To: ' . $agencyEmail
    );

    // Save to Neon database
    $enrollment_id = save_enrollment_to_neon([
        // Agency data
        'agency_name' => $agencyName,
        'agency_contact' => $agencyContact,
        'agency_email' => $agencyEmail,
        // Student data
        'student_first_name' => $studentFirstName,
        'student_last_name' => $studentLastName,
        'student_email' => $studentEmail,
        'student_phone' => $studentPhonePrefix . ' ' . $studentPhone,
        'student_dob' => $studentDOB,
        'student_address' => $studentAddress,
        'student_city' => $studentCity,
        'student_zip' => $studentZip,
        // Course data
        'school' => $school,
        'short_term_course' => $shortTermCourse,
        'long_term_duration' => $longTermDuration,
        'summer_course' => $summerCourse,
        'teacher_course' => $teacherCourse,
        'pathway_program' => $pathwayProgram,
        'immersion_nights' => $immersionNights,
        'weeks' => $weeks,
        'start_date' => $startDate ?: $shortTermStartDate ?: $pathwayStartDate,
        // Housing data
        'housing_required' => $housingRequired === 'yes',
        'accommodation_type' => $accommodationType,
        'housing_type' => $housingType,
        'check_in_date' => $checkInDate,
        'check_out_date' => $checkOutDate,
        'housing_requirements' => $housingRequirements,
        // Transfer data
        'transfer_required' => $transferRequired === 'yes',
        'transfer_type' => $transferType,
        // Visa data
        'admission_letter_required' => $admissionLetterRequired === 'yes',
        // Insurance data
        'insurance_required' => $insuranceRequired === 'yes',
        'insurance_type' => $insuranceType,
        'insurance_start_date' => $insuranceStartDate,
        'insurance_end_date' => $insuranceEndDate
    ]);

    if ($enrollment_id) {
        $body = str_replace(
            '<h2>New Enrollment Form Submission</h2>',
            '<h2>New Enrollment Form Submission</h2><p style="color: #666; font-size: 14px;">Enrollment ID: ENR-' . $enrollment_id . '</p>',
            $body
        );
    }

    // Send to Hablandis admin
    $adminEmail = 'miriam.levie@hablandis.com';
    $sentToAdmin = wp_mail($adminEmail, $subject, $body, $headers);

    // Send to Agency
    $sentToAgency = true;
    if (!empty($agencyEmail)) {
        $sentToAgency = wp_mail($agencyEmail, $subject, $body, $headers);
    }

    if ($sentToAdmin) {
        wp_send_json_success(array('message' => 'Enrollment form submitted successfully'));
    } else {
        wp_send_json_error(array('message' => 'Failed to send email'));
    }
}
add_action('wp_ajax_hablandis_enrollment_form', 'hablandis_enrollment_form_handler');
add_action('wp_ajax_nopriv_hablandis_enrollment_form', 'hablandis_enrollment_form_handler');
