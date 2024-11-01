<?php

if (!defined('ABSPATH')) die('No direct access.');

// https://wordpress.stackexchange.com/questions/191923/sending-multipart-text-html-emails-via-wp-mail-will-likely-get-your-domain-b

// Also slightly modified to prevent other plugins filtering

/**
 * Send mail, similar to PHP's mail
 *
 * A true return value does not automatically mean that the user received the
 * email successfully. It just only means that the method used was able to
 * process the request without any errors.
 *
 * Using the two 'wp_mail_from' and 'wp_mail_from_name' hooks allow from
 * creating a from address like 'Name <email@address.com>' when both are set. If
 * just 'wp_mail_from' is set, then just the email address will be used with no
 * name.
 *
 * The default content type is 'text/plain' which does not allow using HTML.
 * However, you can set the content type of the email by using the
 * 'wp_mail_content_type' filter.
 *
 * If $message is an array, the key of each is used to add as an attachment
 * with the value used as the body. The 'text/plain' element is used as the
 * text version of the body, with the 'text/html' element used as the HTML
 * version of the body. All other types are added as attachments.
 *
 * The default charset is based on the charset used on the blog. The charset can
 * be set using the 'wp_mail_charset' filter.
 *
 * @since 1.2.1
 *
 * @uses PHPMailer
 *
 * @param string|array $to Array or comma-separated list of email addresses to send message.
 * @param string $subject Email subject
 * @param string|array $message Message contents
 * @param string|array $headers Optional. Additional headers.
 * @param string|array $attachments Optional. Files to attach.
 * @return bool Whether the email contents were sent successfully.
 */
function simbamanager_wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    // Compact the input, apply the filters, and extract them back out

    /**
     * Filter the wp_mail() arguments.
     *
     * @since 2.2.0
     *
     * @param array $args A compacted array of wp_mail() arguments, including the "to" email,
     *                    subject, message, headers, and attachments values.
     */
	$filter_this_text = compact( 'to', 'subject', 'headers', 'attachments' );
	$filter_this_text['message'] = is_string($message) ? $message : $message['text/plain'];
    $atts = apply_filters( 'wp_mail', $filter_this_text);
    
    if (is_array($message) && isset($message['text/html'])) {
		$filter_this_html = compact( 'to', 'subject', 'headers', 'attachments' );
		$filter_this_html['message'] = $message['text/html'];
		$atts_html = apply_filters( 'wp_mail', $filter_this_html);
	}
    
    if ( isset( $atts['to'] ) ) {
        $to = $atts['to'];
    }

    if ( isset( $atts['subject'] ) ) {
        $subject = $atts['subject'];
    }

    if (is_array($message)) {
    
		if (!empty($atts['message'])) $message['text/plain'] = $atts['message'];
		if (!empty($atts_html['message'])) $message['text/html'] = $atts_html['message'];
    
    } elseif ( !empty( $atts['message'] ) ) {
        $message = $atts['message'];
    }

    if ( isset( $atts['headers'] ) ) {
        $headers = $atts['headers'];
    }

    if ( isset( $atts['attachments'] ) ) {
        $attachments = $atts['attachments'];
    }

    if ( ! is_array( $attachments ) ) {
        $attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
    }
    global $phpmailer;

    // (Re)create it, if it's gone missing
    if ( ! ( $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) ) {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        $phpmailer = new PHPMailer\PHPMailer\PHPMailer( true );

        $phpmailer::$validator = static function ( $email ) {
            return (bool) is_email( $email );
        };
    }

    // Headers
    if ( empty( $headers ) ) {
        $headers = array();
    } else {
        if ( !is_array( $headers ) ) {
            // Explode the headers out, so this function can take both
            // string headers and an array of headers.
            $tempheaders = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
        } else {
            $tempheaders = $headers;
        }
        $headers = array();
        $cc = array();
        $bcc = array();

        // If it's actually got contents
        if ( !empty( $tempheaders ) ) {
            // Iterate through the raw headers
            foreach ( (array) $tempheaders as $header ) {
                if ( strpos($header, ':') === false ) {
                    if ( false !== stripos( $header, 'boundary=' ) ) {
                        $parts = preg_split('/boundary=/i', trim( $header ) );
                        $boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
                    }
                    continue;
                }
                // Explode them out
                list( $name, $content ) = explode( ':', trim( $header ), 2 );

                // Cleanup crew
                $name    = trim( $name    );
                $content = trim( $content );

                switch ( strtolower( $name ) ) {
                    // Mainly for legacy -- process a From: header if it's there
                    case 'from':
                        $bracket_pos = strpos( $content, '<' );
                        if ( $bracket_pos !== false ) {
                            // Text before the bracketed email is the "From" name.
                            if ( $bracket_pos > 0 ) {
                                $from_name = substr( $content, 0, $bracket_pos - 1 );
                                $from_name = str_replace( '"', '', $from_name );
                                $from_name = trim( $from_name );
                            }

                            $from_email = substr( $content, $bracket_pos + 1 );
                            $from_email = str_replace( '>', '', $from_email );
                            $from_email = trim( $from_email );

                        // Avoid setting an empty $from_email.
                        } elseif ( '' !== trim( $content ) ) {
                            $from_email = trim( $content );
                        }
                        break;
                    case 'content-type':
                        if ( is_array($message) ) {
                            // Multipart email, ignore the content-type header
                            break;
                        }
                        if ( strpos( $content, ';' ) !== false ) {
                            list( $type, $charset_content ) = explode( ';', $content );
                            $content_type = trim( $type );
                            if ( false !== stripos( $charset_content, 'charset=' ) ) {
                                $charset = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
                            } elseif ( false !== stripos( $charset_content, 'boundary=' ) ) {
                                $boundary = trim( str_replace( array( 'BOUNDARY=', 'boundary=', '"' ), '', $charset_content ) );
                                $charset = '';
                            }

                        // Avoid setting an empty $content_type.
                        } elseif ( '' !== trim( $content ) ) {
                            $content_type = trim( $content );
                        }
                        break;
                    case 'cc':
                        $cc = array_merge( (array) $cc, explode( ',', $content ) );
                        break;
                    case 'bcc':
                        $bcc = array_merge( (array) $bcc, explode( ',', $content ) );
                        break;
                    default:
                        // Add it to our grand headers array
                        $headers[trim( $name )] = trim( $content );
                        break;
                }
            }
        }
    }

    // Empty out the values that may be set
    $phpmailer->clearAllRecipients();
    $phpmailer->clearAttachments();
    $phpmailer->clearCustomHeaders();
    $phpmailer->clearReplyTos();

    $phpmailer->Body= '';
    $phpmailer->AltBody= '';

    // From email and name
    // If we don't have a name from the input headers
    if ( !isset( $from_name ) )
        $from_name = 'WordPress';

    /* If we don't have an email from the input headers default to wordpress@$sitename
     * Some hosts will block outgoing mail from this address if it doesn't exist but
     * there's no easy alternative. Defaulting to admin_email might appear to be another
     * option but some hosts may refuse to relay mail from an unknown domain. See
     * https://core.trac.wordpress.org/ticket/5007.
     */

    if ( !isset( $from_email ) ) {
        // Get the site domain and get rid of www.
        $sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );
        if ( 'www.' === substr( $sitename, 0, 4 ) ) {
            $sitename = substr( $sitename, 4 );
        }

        $from_email = 'wordpress@' . $sitename;
    }

    /**
     * Filter the email address to send from.
     *
     * @since 2.2.0
     *
     * @param string $from_email Email address to send from.
     */
    $phpmailer->From = apply_filters( 'wp_mail_from', $from_email );

    /**
     * Filter the name to associate with the "from" email address.
     *
     * @since 2.3.0
     *
     * @param string $from_name Name associated with the "from" email address.
     */
    $phpmailer->FromName = apply_filters( 'wp_mail_from_name', $from_name );

    // Set destination addresses
    if ( !is_array( $to ) )
        $to = explode( ',', $to );

    foreach ( (array) $to as $recipient ) {
        try {
            // Break $recipient into name and address parts if in the format "Foo <bar@baz.com>"
            $recipient_name = '';
            if( preg_match( '/(.*)<(.+)>/', $recipient, $matches ) ) {
                if ( count( $matches ) == 3 ) {
                    $recipient_name = $matches[1];
                    $recipient = $matches[2];
                }
            }
            $phpmailer->addAddress( $recipient, $recipient_name);
        } catch ( PHPMailer\PHPMailer\Exception $e ) {
            continue;
        }
    }

    // If we don't have a charset from the input headers
    if ( !isset( $charset ) )
        $charset = get_bloginfo( 'charset' );

    // Set the content-type and charset

    /**
     * Filter the default wp_mail() charset.
     *
     * @since 2.3.0
     *
     * @param string $charset Default email charset.
     */
    $phpmailer->CharSet = apply_filters( 'wp_mail_charset', $charset );

    // Set mail's subject and body
    $phpmailer->Subject = $subject;

    if ( is_string($message) ) {
        $phpmailer->Body = $message;

        // Set Content-Type and charset
        // If we don't have a content-type from the input headers
        if ( !isset( $content_type ) )
            $content_type = 'text/plain';

        /**
         * Filter the wp_mail() content type.
         *
         * @since 2.3.0
         *
         * @param string $content_type Default wp_mail() content type.
         */
        //$content_type = apply_filters( 'wp_mail_content_type', $content_type );

        $phpmailer->ContentType = $content_type;

        // Set whether it's plaintext, depending on $content_type
        if ( 'text/html' === $content_type )
            $phpmailer->isHTML( true );

        // For backwards compatibility, new multipart emails should use
        // the array style $message. This never really worked well anyway
        if ( false !== stripos( $content_type, 'multipart' ) && ! empty($boundary) )
            $phpmailer->addCustomHeader( sprintf( "Content-Type: %s;\n\t boundary=\"%s\"", $content_type, $boundary ) );
    }
    elseif ( is_array($message) ) {
        foreach ($message as $type => $bodies) {
            foreach ((array) $bodies as $body) {
                if ($type === 'text/html') {
                    $phpmailer->Body = $body;
					// $phpmailer->IsHTML(true); # This line was added in another project. Is it needed?
                }
                elseif ($type === 'text/plain') {
                    $phpmailer->AltBody = $body;
                }
                else {
                    $phpmailer->addAttachment($body, '', 'base64', $type);
                }
            }
        }
    }

    // Add any CC and BCC recipients
    if ( !empty( $cc ) ) {
        foreach ( (array) $cc as $recipient ) {
            try {
                // Break $recipient into name and address parts if in the format "Foo <bar@baz.com>"
                $recipient_name = '';
                if( preg_match( '/(.*)<(.+)>/', $recipient, $matches ) ) {
                    if ( count( $matches ) == 3 ) {
                        $recipient_name = $matches[1];
                        $recipient = $matches[2];
                    }
                }
                $phpmailer->addCC( $recipient, $recipient_name );
            } catch ( PHPMailer\PHPMailer\Exception $e ) {
                continue;
            }
        }
    }

    if ( !empty( $bcc ) ) {
        foreach ( (array) $bcc as $recipient) {
            try {
                // Break $recipient into name and address parts if in the format "Foo <bar@baz.com>"
                $recipient_name = '';
                if( preg_match( '/(.*)<(.+)>/', $recipient, $matches ) ) {
                    if ( count( $matches ) == 3 ) {
                        $recipient_name = $matches[1];
                        $recipient = $matches[2];
                    }
                }
                $phpmailer->addBCC( $recipient, $recipient_name );
            } catch ( PHPMailer\PHPMailer\Exception $e ) {
                continue;
            }
        }
    }

    // Set to use PHP's mail()
    $phpmailer->isMail();

    // Set custom headers
    if ( !empty( $headers ) ) {
        foreach ( (array) $headers as $name => $content ) {
            // Only add custom headers not added automatically by PHPMailer.
            if ( ! in_array( $name, array( 'MIME-Version', 'X-Mailer' ), true ) ) {
                try {
                    $phpmailer->addCustomHeader( sprintf( '%1$s: %2$s', $name, $content ) );
                } catch ( PHPMailer\PHPMailer\Exception $e ) {
                    continue;
                }
            }
        }
    }

    if ( !empty( $attachments ) ) {
        foreach ( $attachments as $attachment ) {
            try {
                $phpmailer->addAttachment($attachment);
            } catch ( PHPMailer\PHPMailer\Exception $e ) {
                continue;
            }
        }
    }

    /**
     * Fires after PHPMailer is initialized.
     *
     * @since 2.2.0
     *
     * @param PHPMailer &$phpmailer The PHPMailer instance, passed by reference.
     */
    // For some reason I don't understand, not calling the action prevents any mail from going out; whereas, emptying it still works
    
    // WP_Hook object: https://make.wordpress.org/core/2016/09/08/wp_hook-next-generation-actions-and-filters/ - changes in WP 4.7
    
    global $wp_filter;
    
    $empty_hook = class_exists('WP_Hook') ? new WP_Hook() : array();
    $any_actions = isset($wp_filter['phpmailer_init']) ? $wp_filter['phpmailer_init'] : $empty_hook;
    $wp_filter['phpmailer_init'] = $empty_hook;
    do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );
    $wp_filter['phpmailer_init'] = $any_actions;

    do_action_ref_array( 'simbamanager_phpmailer_init', array( &$phpmailer ) );

    // Send!
    try {
        return $phpmailer->send();
    } catch ( PHPMailer\PHPMailer\Exception $e ) {
        return false;
    }
}
