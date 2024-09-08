<?php
/*
Plugin Name: Spotify Queue Plugin
Description: Erlaubt es, sich im Backend bei Spotify anzumelden und im Frontend Songs zur Spotify-Queue hinzuzufügen.
Version: 9.17Alpha
Author: LordSteini
*/


define('SPOTIFY_REDIRECT_URI', 'http://localhost:8888/wordpress/wp-admin/admin.php?page=spotify-queue&spotify-auth=1');

// Pufferung der Ausgabe starten
ob_start();

// Register a new setting for the queue codes
add_action('admin_init', 'spotify_queue_register_queue_codes');
function spotify_queue_register_queue_codes() {
    register_setting('spotify_queue_options_group', 'spotify_queue_codes');
}

// Add Queue Codes management section in settings page
add_action('admin_init', 'spotify_queue_register_queue_code_settings');
function spotify_queue_register_queue_code_settings() {
    add_settings_section('spotify_queue_code_section', 'Spotify Queue Codes', null, 'spotify_queue');
    add_settings_field('spotify_queue_codes', 'Queue Codes', 'spotify_queue_codes_callback', 'spotify_queue', 'spotify_queue_code_section');
}

function spotify_queue_codes_callback() {
    $codes = spotify_queue_get_all_queue_codes();
    ?>
    <table>
        <thead>
            <tr>
                <th>User ID</th>
                <th>Queue Code</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($codes as $user_id => $queue_code) : ?>
                <tr>
                    <td><?php echo esc_html($user_id); ?></td>
                    <td><?php echo esc_html($queue_code); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <h3>Neuen Queue-Code hinzufügen</h3>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="add_queue_code">
        <p>
            <label for="new_user_id">User ID:</label>
            <input type="text" name="new_user_id" id="new_user_id">
        </p>
        <p>
            <label for="new_queue_code">Queue Code:</label>
            <input type="text" name="new_queue_code" id="new_queue_code">
        </p>
        <p>
            <input type="submit" value="Hinzufügen">
        </p>
    </form>
    <?php
}

// Handle the form submission to add new queue codes
add_action('admin_post_add_queue_code', 'spotify_queue_add_new_queue_code');
function spotify_queue_add_new_queue_code() {
    if (isset($_POST['new_user_id']) && isset($_POST['new_queue_code'])) {
        $user_id = sanitize_text_field($_POST['new_user_id']);
        $queue_code = sanitize_text_field($_POST['new_queue_code']);
        
        spotify_queue_set_user_queue_code($user_id, $queue_code);
        
        wp_redirect(admin_url('admin.php?page=spotify-queue'));
        exit;
    } else {
        wp_redirect(admin_url('admin.php?page=spotify-queue&error=missing_parameters'));
        exit;
    }
}


// Add menu item in admin panel
add_action('admin_menu', 'spotify_queue_menu');
function spotify_queue_menu() {
    add_menu_page('Spotify Queue Settings', 'Spotify Queue', 'manage_options', 'spotify-queue', 'spotify_queue_settings_page');
}

// Settings page content
function spotify_queue_settings_page() {
    if (isset($_GET['spotify-auth'])) {
        spotify_queue_handle_auth();
        return;
    }
    
    if (isset($_GET['spotify-disconnect'])) {
        spotify_queue_handle_disconnect();
        return;
    }
    
    ?>
    <div class="wrap">
        <h1>Spotify Queue Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('spotify_queue_options_group');
            do_settings_sections('spotify_queue');
            submit_button();
            ?>
        </form>
        <a href="<?php echo spotify_queue_get_auth_url(); ?>">Mit Spotify verbinden</a>
        <br><br>
        <a href="<?php echo admin_url('admin.php?page=spotify-queue&spotify-disconnect=1'); ?>">Von Spotify trennen</a>
    </div>
    <?php
}

// Register settings
add_action('admin_init', 'spotify_queue_register_settings');
function spotify_queue_register_settings() {
    register_setting('spotify_queue_options_group', 'spotify_client_id');
    register_setting('spotify_queue_options_group', 'spotify_client_secret');
    register_setting('spotify_queue_options_group', 'spotify_access_token');
    register_setting('spotify_queue_options_group', 'spotify_refresh_token');
    register_setting('spotify_queue_options_group', 'spotify_token_expires');
    register_setting('spotify_queue_options_group', 'spotify_queue_pause_time');
    register_setting('spotify_queue_options_group', 'spotify_custom_error_message');

    add_settings_section('spotify_queue_section', 'Spotify API Einstellungen', null, 'spotify_queue');
    add_settings_field('spotify_client_id', 'Client ID', 'spotify_client_id_callback', 'spotify_queue', 'spotify_queue_section');
    add_settings_field('spotify_client_secret', 'Client Secret', 'spotify_client_secret_callback', 'spotify_queue', 'spotify_queue_section');
    add_settings_field('spotify_queue_cooldown_time', 'Cooldown Time (minutes)', 'spotify_queue_cooldown_time_callback', 'spotify_queue', 'spotify_queue_section');
    add_settings_field('spotify_custom_error_message', 'Custom Error Message', 'spotify_custom_error_message_callback', 'spotify_queue', 'spotify_queue_section');
}

function spotify_client_id_callback() {
    $client_id = get_option('spotify_client_id');
    echo '<input type="text" name="spotify_client_id" value="' . esc_attr($client_id) . '" />';
}

function spotify_client_secret_callback() {
    $client_secret = get_option('spotify_client_secret');
    echo '<input type="text" name="spotify_client_secret" value="' . esc_attr($client_secret) . '" />';
}

function spotify_queue_cooldown_time_callback() {
    $cooldown_time = get_option('spotify_queue_cooldown_time', 20); // Default to 20 minutes
    echo '<input type="number" min="1" name="spotify_queue_cooldown_time" value="' . esc_attr($cooldown_time) . '" /> minutes';
}

function spotify_custom_error_message_callback() {
    $custom_message = get_option('spotify_custom_error_message', 'Das Hinzufügen von Songs in die Queue ist momentan von einem Administrator pausiert worden. Bitte versuche es später erneut.');
    echo '<textarea name="spotify_custom_error_message" rows="4" cols="50">' . esc_textarea($custom_message) . '</textarea>';
}

// Handle Spotify authentication
function spotify_queue_handle_auth() {
    $client_id = get_option('spotify_client_id');
    $client_secret = get_option('spotify_client_secret');
    $code = $_GET['code'];

    $response = wp_remote_post('https://accounts.spotify.com/api/token', array(
        'body' => array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => SPOTIFY_REDIRECT_URI,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ),
    ));

    if (is_wp_error($response)) {
        echo 'Error during authentication.';
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['access_token'])) {
        update_option('spotify_access_token', $data['access_token']);
        update_option('spotify_refresh_token', $data['refresh_token']);
        update_option('spotify_token_expires', time() + $data['expires_in']);
    }

    wp_redirect(admin_url('admin.php?page=spotify-queue'));
    exit;
}

// Get Spotify authentication URL
function spotify_queue_get_auth_url() {
    $client_id = get_option('spotify_client_id');
    $redirect_uri = SPOTIFY_REDIRECT_URI;
    $scopes = 'user-modify-playback-state user-read-playback-state';

    return 'https://accounts.spotify.com/authorize?response_type=code&client_id=' . $client_id . '&redirect_uri=' . urlencode($redirect_uri) . '&scope=' . urlencode($scopes);
}

// Handle Spotify disconnection
function spotify_queue_handle_disconnect() {
    delete_option('spotify_access_token');
    delete_option('spotify_refresh_token');
    delete_option('spotify_token_expires');

    wp_redirect(admin_url('admin.php?page=spotify-queue'));
    exit;
}
/*
// Refresh the access token
function spotify_queue_refresh_access_token() {
    $client_id = get_option('spotify_client_id');
    $client_secret = get_option('spotify_client_secret');
    $refresh_token = get_option('spotify_refresh_token');

    $response = wp_remote_post('https://accounts.spotify.com/api/token', array(
        'body' => array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ),
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['access_token'])) {
        update_option('spotify_access_token', $data['access_token']);
        update_option('spotify_token_expires', time() + $data['expires_in']);
        return $data['access_token'];
    }

    return false;
}

// Get access token, refreshing if necessary
function spotify_queue_get_access_token() {
    $access_token = get_option('spotify_access_token');
    $expires = get_option('spotify_token_expires');

    if (time() > $expires) {
        $access_token = spotify_queue_refresh_access_token();
    }

    return $access_token;
} 
*/

// Get all queue codes
function spotify_queue_get_all_queue_codes() {
    $codes = get_option('spotify_queue_codes', array());
    if (!is_array($codes)) {
        $codes = array();
    }
    return $codes;
}

// Set a queue code for a user
function spotify_queue_set_user_queue_code($user_id, $queue_code) {
    $codes = spotify_queue_get_all_queue_codes();
    $codes[$user_id] = $queue_code;
    update_option('spotify_queue_codes', $codes);
}

// Get a queue code for a user
function spotify_queue_get_user_queue_code($user_id) {
    $codes = spotify_queue_get_all_queue_codes();
    return isset($codes[$user_id]) ? $codes[$user_id] : '';
}

// Check if a queue code exists
function spotify_queue_code_exists($queue_code) {
    $codes = spotify_queue_get_all_queue_codes();
    return in_array($queue_code, $codes);
}

// AJAX handler to verify queue code
add_action('wp_ajax_spotify_queue_verify_code', 'spotify_queue_verify_code');
add_action('wp_ajax_nopriv_spotify_queue_verify_code', 'spotify_queue_verify_code');
function spotify_queue_verify_code() {
    check_ajax_referer('spotify_queue_nonce', 'nonce');
    
    $queue_code = sanitize_text_field($_POST['queue_code']);
    $codes = spotify_queue_get_all_queue_codes();
    
    if (in_array($queue_code, $codes)) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Invalid Queue Code.');
    }
}

// AJAX handler for searching songs
add_action('wp_ajax_spotify_queue_search', 'spotify_queue_search');
add_action('wp_ajax_nopriv_spotify_queue_search', 'spotify_queue_search');
function spotify_queue_search() {
    if (!isset($_POST['query'])) {
        wp_send_json_error('Missing query parameter');
        return;
    }

    $query = sanitize_text_field($_POST['query']);
    $access_token = spotify_queue_get_access_token();

    $response = wp_remote_get('https://api.spotify.com/v1/search?q=' . urlencode($query) . '&type=track&limit=10', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
        )
    ));

    if (is_wp_error($response)) {
        wp_send_json_error('Search request failed.');
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['tracks']['items'])) {
        wp_send_json_success(array('results' => $data['tracks']['items']));
    } else {
        wp_send_json_error('No results found.');
    }
}

// AJAX handler for adding song to queue
add_action('wp_ajax_spotify_queue_add', 'spotify_queue_add');
add_action('wp_ajax_nopriv_spotify_queue_add', 'spotify_queue_add');
function spotify_queue_add() {
    if (!isset($_POST['uri']) || !isset($_POST['queue_code']) || !isset($_POST['song_name']) || !isset($_POST['song_artist'])) {
        wp_send_json_error('Missing parameters');
        return;
    }

    $uri = sanitize_text_field($_POST['uri']);
    $queue_code = sanitize_text_field($_POST['queue_code']);
    $song_name = sanitize_text_field($_POST['song_name']);
    $song_artist = sanitize_text_field($_POST['song_artist']);

    // Check if cooldown time option exists, default to 20 minutes if not set
    $cooldown_time = get_option('spotify_queue_cooldown_time', 20) * 60; // Convert minutes to seconds

    // Check if the song is in cooldown period
    $last_played = get_transient('spotify_last_played_' . $uri);
    if ($last_played !== false && (time() - $last_played) < $cooldown_time) {
        $remaining_time = ceil(($cooldown_time - (time() - $last_played)) / 60); // Convert remaining seconds to minutes
        $error_data = array(
            'song_name' => $song_name,
            'song_artist' => $song_artist,
            'remaining_time' => $remaining_time,
            'cooldown_minutes' => get_option('spotify_queue_cooldown_time', 20) // Pass the cooldown time to JavaScript
        );
        wp_send_json_error($error_data);
        return;
    }

    // Get the access token
    $access_token = spotify_queue_get_access_token();
    if (!$access_token) {
        wp_send_json_error('Could not retrieve access token.');
        return;
    }

    // Make the request to add the song to the Spotify queue
    $response = wp_remote_post('https://api.spotify.com/v1/me/player/queue?uri=' . urlencode($uri), array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
        )
    ));

    if (is_wp_error($response)) {
        wp_send_json_error('Add to queue request failed.');
        return;
    }

    // Set transient for the song URI to track last played time
    set_transient('spotify_last_played_' . $uri, time(), $cooldown_time);

    wp_send_json_success();
}

// Function to refresh the access token
function spotify_queue_refresh_access_token() {
    $client_id = get_option('spotify_client_id');
    $client_secret = get_option('spotify_client_secret');
    $refresh_token = get_option('spotify_refresh_token');

    $response = wp_remote_post('https://accounts.spotify.com/api/token', array(
        'body' => array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ),
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['access_token'])) {
        update_option('spotify_access_token', $data['access_token']);
        update_option('spotify_token_expires', time() + $data['expires_in']);
        return $data['access_token'];
    }

    return false;
}

// Function to get access token, refreshing if necessary
function spotify_queue_get_access_token() {
    $access_token = get_option('spotify_access_token');
    $expires = get_option('spotify_token_expires');

    if (time() > $expires) {
        $access_token = spotify_queue_refresh_access_token();
    }

    return $access_token;
}

// Enqueue frontend script and style
add_action('wp_enqueue_scripts', 'spotify_queue_enqueue_assets');
function spotify_queue_enqueue_assets() {
    wp_enqueue_script('spotify-queue', plugin_dir_url(__FILE__) . 'js/spotify-queue.js', array('jquery'), null, true);
    wp_enqueue_style('spotify-queue', plugin_dir_url(__FILE__) . 'spotify-queue.css');
    wp_localize_script('spotify-queue', 'spotifyQueue', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'cooldown_minutes' => get_option('spotify_queue_cooldown_time', 20)
    ));
}

// Shortcode to display the search and queue interface with queue code input
add_shortcode('spotify_queue', 'spotify_queue_shortcode');
function spotify_queue_shortcode() {
    ob_start();
    ?>
    <div id="spotify-queue-container">
        <div id="queue-code-container">
            <p>User ID: <span id="display-user-id"></span></p>
            <p>Queue Code: <span id="display-queue-code"></span></p>
            <input type="text" id="queue-code-input" placeholder="Geben Sie Ihren Queue-Code ein...">
            <button id="queue-code-button">Bestätigen</button>
        </div>
        <div id="spotify-search-container" style="display:none;">
            <input type="text" id="spotify-search-input" placeholder="Suche nach einem Song oder Interpreten...">
            <button id="spotify-search-button">Suchen</button>
            <button id="change-queue-code-button">Anderen Queue-Code verwenden</button>
            <div id="spotify-search-results"></div>
            <?php echo do_shortcode('[spotify_current_track]'); ?>
        </div>
    </div>
    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            var queueCode = sessionStorage.getItem('spotifyQueueCode');
            var userId = sessionStorage.getItem('spotifyUserId');

            if (queueCode && userId) {
                $('#queue-code-container').hide();
                $('#spotify-search-container').show();
                $('#display-user-id').text(userId);
                $('#display-queue-code').text(queueCode);
            }

            $('#queue-code-button').on('click', function () {
                var enteredCode = $('#queue-code-input').val();
                var userId = 'some_user_id';  // Replace with logic to get actual user ID
                sessionStorage.setItem('spotifyQueueCode', enteredCode);
                sessionStorage.setItem('spotifyUserId', userId);
                $('#display-user-id').text(userId);
                $('#display-queue-code').text(enteredCode);
                $('#queue-code-container').hide();
                $('#spotify-search-container').show();
            });

            $('#change-queue-code-button').on('click', function () {
                sessionStorage.removeItem('spotifyQueueCode');
                sessionStorage.removeItem('spotifyUserId');
                $('#queue-code-container').show();
                $('#spotify-search-container').hide();
            });

            $('#spotify-search-button').on('click', function () {
                var query = $('#spotify-search-input').val();
                $.ajax({
                    url: spotifyQueue.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'spotify_queue_search',
                        query: query
                    },
                    success: function (response) {
                        if (response.success) {
                            var results = response.data.results;
                            var resultsContainer = $('#spotify-search-results');
                            resultsContainer.empty();
                            results.forEach(function (song) {
                                var songElement = $('<div>').addClass('spotify-song').append(
                                    $('<img>').attr('src', song.album.images[0].url).addClass('spotify-cover'),
                                    $('<div>').addClass('spotify-info').append(
                                        $('<div>').addClass('spotify-title').text(song.name),
                                        $('<div>').addClass('spotify-artist').text(song.artists.map(artist => artist.name).join(', '))
                                    ),
                                    $('<button>').addClass('spotify-add-button').html('+').on('click', function () {
                                        addToQueue(song.uri, song.name, song.artists.map(artist => artist.name).join(', '));
                                    })
                                );
                                resultsContainer.append(songElement);
                            });
                        }
                    }
                });
            });

            function addToQueue(songUri, songName, songArtist) {
                var queueCode = sessionStorage.getItem('spotifyQueueCode');
                $.ajax({
                    url: spotifyQueue.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'spotify_queue_add',
                        uri: songUri,
                        song_name: songName,
                        song_artist: songArtist,
                        queue_code: queueCode
                    },
                    success: function (response) {
                        if (response.success) {
                            var message = 'Song "' + songName + '" von ' + songArtist + ' wurde zur Wiedergabeliste hinzugefügt. Bitte beachte, dass es etwas dauern kann bis dein Song gespielt wird.';
                            var successMessage = $('<div>').addClass('spotify-success-message').text(message);
                            $('body').append(successMessage);
                            setTimeout(function () {
                                successMessage.fadeOut('slow', function () {
                                    $(this).remove();
                                });
                            }, 10000);

                            $('#spotify-search-input').val('');
                            $('#spotify-search-results').empty();
                        } else {
                            var errorData = response.data;
                            var errorMessage = $('<div>').addClass('spotify-error-message').text('Der Song "' + errorData.song_name + '" von ' + errorData.song_artist + ' befindet sich noch für ' + errorData.remaining_time + ' Minuten in der Abkühlphase. Bitte wähle einen anderen Song.');
                            $('body').append(errorMessage);
                            setTimeout(function () {
                                errorMessage.fadeOut('slow', function () {
                                    $(this).remove();
                                });
                            }, 10000);
                        }
                    }
                });
            }
        });
    </script>
    <?php
    return ob_get_clean();
}

// Shortcode to display the currently playing track
add_shortcode('spotify_current_track', 'spotify_current_track_shortcode');
function spotify_current_track_shortcode() {
    ob_start();
    ?>
    <div id="current_track_container"></div>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var queueCode = sessionStorage.getItem('spotifyQueueCode');
            if (queueCode) {
                jQuery.ajax({
                    url: spotifyQueue.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'spotify_current_track',
                        queue_code: queueCode
                    },
                    success: function (response) {
                        if (response.success) {
                            jQuery('#current_track_container').html(response.data);
                        } else {
                            jQuery('#current_track_container').html('<p style="color: white; align-items: center;">Fehler beim Abrufen des aktuell spielenden Titels.</p>');
                        }
                    },
                    error: function () {
                        jQuery('#current_track_container').html('<p style="color: white; align-items: center;">Fehler beim Abrufen des aktuell spielenden Titels.</p>');
                    }
                });
            } else {
                jQuery('#current_track_container').html('<p style="color: white; align-items: center;">Bitte gib einen Queue-Code ein.</p>');
            }
        });
    </script>
    <?php
    return ob_get_clean();
}

// AJAX handler to get the currently playing track
add_action('wp_ajax_spotify_current_track', 'spotify_current_track_ajax_handler');
add_action('wp_ajax_nopriv_spotify_current_track', 'spotify_current_track_ajax_handler');
function spotify_current_track_ajax_handler() {
    if (!isset($_POST['queue_code'])) {
        wp_send_json_error('Missing queue code');
    }

    $queue_code = sanitize_text_field($_POST['queue_code']);
    $access_token = spotify_queue_get_access_token();

    $response = wp_remote_get('https://api.spotify.com/v1/me/player/currently-playing', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
        ),
    ));

    if (is_wp_error($response)) {
        wp_send_json_error('API request failed');
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data) || empty($data['item'])) {
        wp_send_json_error('No track is currently playing');
    }

    $track = $data['item'];
    $track_name = $track['name'];
    $track_artist = implode(', ', array_map(function ($artist) {
        return $artist['name'];
    }, $track['artists']));
    $track_album = $track['album']['name'];
    $track_image = $track['album']['images'][0]['url'];

    ob_start();
    ?>
    <style>
        .spotify-current-track {
            background-color: white;
            border-radius: 15px;
            display: flex;
            align-items: center;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            max-width: 400px;
            margin: 0 auto;
        }

        .spotify-current-track img {
            border-radius: 15px;
            width: 100px;
            height: 100px;
            object-fit: cover;
            margin-right: 20px;
        }

        .spotify-current-track div {
            color: black;
        }

        .spotify-current-track strong {
            display: block;
            margin-top: 5px;
        }
    </style>
    <div class="spotify-current-track">
        <img src="<?php echo esc_url($track_image); ?>" alt="Album Cover">
        <div>
            <strong>Title:</strong> <?php echo esc_html($track_name); ?><br>
            <strong>Artist:</strong> <?php echo esc_html($track_artist); ?><br>
            <strong>Album:</strong> <?php echo esc_html($track_album); ?>
        </div>
    </div>
    <?php
    $html = ob_get_clean();
    wp_send_json_success($html);
}

// Shortcode to display the currently playing track in full-screen mode with upcoming tracks
add_shortcode('spotify_current_track_big', 'spotify_current_track_big_shortcode');
function spotify_current_track_big_shortcode() {
    $access_token_big = spotify_queue_get_access_token();

    if (!$access_token_big) {
        return '<p style="color: red; text-align: center;">Fehler: Konnte keinen gültigen Access Token abrufen.</p>';
    }

    // Fetch currently playing track
    $response_big = wp_remote_get('https://api.spotify.com/v1/me/player/currently-playing', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token_big,
        ),
    ));

    if (is_wp_error($response_big)) {
        return '<p style="color: red; text-align: center;">Fehler: Abruf des aktuell spielenden Titels fehlgeschlagen.</p>';
    }

    $body_big = wp_remote_retrieve_body($response_big);
    $data_big = json_decode($body_big, true);

    if (empty($data_big) || empty($data_big['item'])) {
        return '<p style="color: white; text-align: center;">Aktuell wird kein Titel gespielt.</p>';
    }

    $track_big = $data_big['item'];
    $track_name_big = $track_big['name'];
    $track_artist_big = implode(', ', array_map(function($artist) { return $artist['name']; }, $track_big['artists']));
    $track_album_big = $track_big['album']['name'];
    $track_image_big = $track_big['album']['images'][0]['url'];

    // Fetch upcoming tracks
    $queue_response_big = wp_remote_get('https://api.spotify.com/v1/me/player/queue', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token_big,
        ),
    ));

    $queue_body_big = wp_remote_retrieve_body($queue_response_big);
    $queue_data_big = json_decode($queue_body_big, true);

    $upcoming_tracks_big = isset($queue_data_big['queue']) ? array_slice($queue_data_big['queue'], 0, 10) : [];

    ob_start();
    ?>
    <style>
        .spotify-current-track-big {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #181818;
            color: white;
            height: 100vh;
            width: 100vw;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            text-align: center;
            padding: 20px;
        }
        .spotify-current-track-big .current-track-big {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .spotify-current-track-big img {
            width: 300px;
            height: 300px;
            object-fit: cover;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .spotify-current-track-big h1 {
            font-size: 3em;
            margin: 10px 0;
        }
        .spotify-current-track-big h2 {
            font-size: 2em;
            margin: 10px 0;
            color: #1DB954;
        }
        .spotify-current-track-big h3 {
            font-size: 1.5em;
            margin: 10px 0;
            color: #b3b3b3;
        }
        .spotify-current-track-big .upcoming-tracks-big {
            width: 300px;
            max-height: 100vh;
            overflow-y: auto;
            padding: 10px;
            background-color: #282828;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        }
        .spotify-current-track-big .upcoming-track-big {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        .spotify-current-track-big .upcoming-track-big img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
            margin-right: 10px;
        }
        .spotify-current-track-big .upcoming-track-big .track-info-big {
            text-align: left;
            flex: 1;
        }
        .spotify-current-track-big .upcoming-track-big .track-info-big strong {
            display: block;
            color: white;
        }
        .spotify-current-track-big .upcoming-track-big .track-info-big span {
            color: #b3b3b3;
        }
    </style>
    <div class="spotify-current-track-big">
        <div class="current-track-big">
            <img src="<?php echo esc_url($track_image_big); ?>" alt="Album Cover">
            <h1><?php echo esc_html($track_name_big); ?></h1>
            <h2><?php echo esc_html($track_artist_big); ?></h2>
            <h3><?php echo esc_html($track_album_big); ?></h3>
        </div>
        <div class="upcoming-tracks-big">
            <h2>Nächste Tracks</h2>
            <?php if (!empty($upcoming_tracks_big)): ?>
                <?php foreach ($upcoming_tracks_big as $track): ?>
                    <div class="upcoming-track-big">
                        <img src="<?php echo esc_url($track['album']['images'][0]['url']); ?>" alt="Album Cover">
                        <div class="track-info-big">
                            <strong><?php echo esc_html($track['name']); ?></strong>
                            <span><?php echo esc_html(implode(', ', array_map(function($artist) { return $artist['name']; }, $track['artists']))); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: white;">Keine weiteren Tracks in der Warteschlange.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// AJAX handler to get the current track and upcoming queue
add_action('wp_ajax_get_spotify_current_track', 'get_spotify_current_track');
add_action('wp_ajax_nopriv_get_spotify_current_track', 'get_spotify_current_track');
function get_spotify_current_track() {
    $access_token_big = spotify_queue_get_access_token();

    if (!$access_token_big) {
        wp_send_json_error('Fehler: Konnte keinen gültigen Access Token abrufen.');
        return;
    }

    // Fetch currently playing track
    $response_big = wp_remote_get('https://api.spotify.com/v1/me/player/currently-playing', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token_big,
        ),
    ));

    if (is_wp_error($response_big)) {
        wp_send_json_error('Fehler: Abruf des aktuell spielenden Titels fehlgeschlagen.');
        return;
    }

    $body_big = wp_remote_retrieve_body($response_big);
    $data_big = json_decode($body_big, true);

    if (empty($data_big) || empty($data_big['item'])) {
        wp_send_json_error('Aktuell wird kein Titel gespielt.');
        return;
    }

    $track_big = $data_big['item'];
    $track_name_big = $track_big['name'];
    $track_artist_big = implode(', ', array_map(function($artist) { return $artist['name']; }, $track_big['artists']));
    $track_album_big = $track_big['album']['name'];
    $track_image_big = $track_big['album']['images'][0]['url'];

    // Fetch upcoming tracks
    $queue_response_big = wp_remote_get('https://api.spotify.com/v1/me/player/queue', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token_big,
        ),
    ));

    $queue_body_big = wp_remote_retrieve_body($queue_response_big);
    $queue_data_big = json_decode($queue_body_big, true);

    $upcoming_tracks_big = isset($queue_data_big['queue']) ? array_slice($queue_data_big['queue'], 0, 10) : [];

    wp_send_json_success(array(
        'track_name_big' => $track_name_big,
        'track_artist_big' => $track_artist_big,
        'track_album_big' => $track_album_big,
        'track_image_big' => $track_image_big,
        'upcoming_tracks_big' => $upcoming_tracks_big
    ));
	
	add_action('wp_footer', 'spotify_current_track_big_script');
	function spotify_current_track_big_script() {
	    ?>
	    <script>
	        function updateSpotifyCurrentTrack() {
	            jQuery.ajax({
	                url: '<?php echo admin_url('admin-ajax.php'); ?>',
	                method: 'POST',
	                data: {
	                    action: 'get_spotify_current_track'
	                },
	                success: function(response) {
	                    if (response.success) {
	                        // Update currently playing track
	                        jQuery('.spotify-current-track-big .current-track-big img').attr('src', response.data.track_image_big);
	                        jQuery('.spotify-current-track-big .current-track-big h1').text(response.data.track_name_big);
	                        jQuery('.spotify-current-track-big .current-track-big h2').text(response.data.track_artist_big);
	                        jQuery('.spotify-current-track-big .current-track-big h3').text(response.data.track_album_big);

	                        // Update upcoming tracks
	                        let upcomingTracksContainer = jQuery('.spotify-current-track-big .upcoming-tracks-big');
	                        upcomingTracksContainer.html('<h2>Nächste Tracks</h2>'); // Clear and add header
	                        if (response.data.upcoming_tracks_big.length > 0) {
	                            response.data.upcoming_tracks_big.forEach(function(track) {
	                                let trackHtml = `
	                                    <div class="upcoming-track-big">
	                                        <img src="${track.album.images[0].url}" alt="Album Cover">
	                                        <div class="track-info-big">
	                                            <strong>${track.name}</strong>
	                                            <span>${track.artists.map(artist => artist.name).join(', ')}</span>
	                                        </div>
	                                    </div>
	                                `;
	                                upcomingTracksContainer.append(trackHtml);
	                            });
	                        } else {
	                            upcomingTracksContainer.append('<p style="color: white;">Keine weiteren Tracks in der Warteschlange.</p>');
	                        }
	                    } else {
	                        console.error('Error fetching track:', response.data);
	                    }
	                },
	                error: function(error) {
	                    console.error('AJAX error:', error);
	                }
	            });
	        }

	        // Refresh every 10 seconds
	        setInterval(updateSpotifyCurrentTrack, 10000);
	        updateSpotifyCurrentTrack(); // Initial call to load the data immediately
	    </script>
	    <?php
	}
	
}

?>
