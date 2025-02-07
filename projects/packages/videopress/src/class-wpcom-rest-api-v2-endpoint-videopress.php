<?php
/**
 * REST API endpoint for managing VideoPress metadata.
 *
 * @package automattic/jetpack
 * @since-jetpack 9.3.0
 * @since 0.1.3
 */

namespace Automattic\Jetpack\VideoPress;

use Automattic\Jetpack\Connection\Client;
use Automattic\Jetpack\Constants;
use Jetpack_Options;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

/**
 * VideoPress wpcom api v2 endpoint
 */
class WPCOM_REST_API_V2_Endpoint_VideoPress extends WP_REST_Controller {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wpcom/v2';
		$this->rest_base = 'videopress';

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 */
	public function register_routes() {
		// Meta Route.
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/meta',
			array(
				'args'                => array(
					'id'              => array(
						'description'       => __( 'The post id for the attachment.', 'jetpack-videopress-pkg' ),
						'type'              => 'int',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'title'           => array(
						'description'       => __( 'The title of the video.', 'jetpack-videopress-pkg' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description'     => array(
						'description'       => __( 'The description of the video.', 'jetpack-videopress-pkg' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'rating'          => array(
						'description'       => __( 'The video content rating. One of G, PG-13 or R-17', 'jetpack-videopress-pkg' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'display_embed'   => array(
						'description'       => __( 'Display the share menu in the player.', 'jetpack-videopress-pkg' ),
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'allow_download'  => array(
						'description'       => __( 'Display download option and allow viewers to download this video', 'jetpack-videopress-pkg' ),
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'privacy_setting' => array(
						'description'       => __( 'How to determine if the video should be public or private', 'jetpack-videopress-pkg' ),
						'type'              => 'int',
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'videopress_block_update_meta' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Poster Route.
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/(?P<video_guid>\w+)/poster',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'videopress_block_get_poster' ),
					'permission_callback' => function () {
						return current_user_can( 'read' );
					},
				),
				array(
					'args'                => array(
						'at_time'              => array(
							'description'       => __( 'The time in the video to use as the poster frame.', 'jetpack-videopress-pkg' ),
							'type'              => 'int',
							'required'          => false,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
						'is_millisec'          => array(
							'description'       => __( 'Whether the time is in milliseconds or seconds.', 'jetpack-videopress-pkg' ),
							'type'              => 'boolean',
							'required'          => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'poster_attachment_id' => array(
							'description'       => __( 'The attachment id of the poster image.', 'jetpack-videopress-pkg' ),
							'type'              => 'int',
							'required'          => false,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'videopress_block_update_poster' ),
					'permission_callback' => function () {
						return current_user_can( 'upload_files' );
					},
				),
			)
		);
	}

	/**
	 * Retrieve a Upload Token via WPCOM api.
	 *
	 * @param string $blog_id The blog id.
	 * @return string
	 * @throws \WP_Error If token is empty or is had an error.
	 */
	public function videopress_get_upload_token( $blog_id ) {
		$args = array(
			'method' => 'POST',
		);

		$endpoint = "sites/{$blog_id}/media/token";
		$result   = Client::wpcom_json_api_request_as_blog( $endpoint, Client::WPCOM_JSON_API_VERSION, $args );

		if ( is_wp_error( $result ) ) {
			throw new WP_Error( 'videopress_wp_error', __( 'Could not obtain a VideoPress upload token. Please try again later.', 'jetpack-videopress-pkg' ) );
		}

		$response = json_decode( $result['body'], true );

		if ( empty( $response['upload_token'] ) ) {
			throw new WP_Error( 'videopress_no_token', __( 'Could not obtain a VideoPress upload token. Please try again later.', 'jetpack-videopress-pkg' ) );
		}

		return $response['upload_token'];
	}

	/**
	 * Retrieve a Playback JWT via WPCOM api.
	 *
	 * @param string $guid The VideoPress GUID.
	 * @return string
	 */
	public function request_jwt_from_wpcom( $guid ) {
		if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
			$blog_id = get_current_blog_id();
		} else {
			$blog_id = Jetpack_Options::get_option( 'id' );
		}

		$args = array(
			'method' => 'POST',
		);

		$endpoint = "sites/{$blog_id}/media/videopress-playback-jwt/{$guid}";

		$result = Client::wpcom_json_api_request_as_blog( $endpoint, 'v2', $args, null, 'wpcom' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = json_decode( $result['body'], true );

		if ( empty( $response['metadata_token'] ) ) {
			return false;
		}

		return $response['metadata_token'];
	}

	/**
	 * Hit WPCOM poster endpoint.
	 *
	 * @param string $video_guid  The VideoPress GUID.
	 * @param array  $args        Request args.
	 * @param array  $body        Request body.
	 * @param string $query       Request query.
	 * @return WP_REST_Response|WP_Error
	 */
	public function wpcom_poster_request( $video_guid, $args, $body = null, $query = '' ) {
		$query    = $query !== '' ? '?' . $query : '';
		$endpoint = 'videos/' . $video_guid . '/poster' . $query;

		$url = sprintf(
			'%s/%s/v%s/%s',
			Constants::get_constant( 'JETPACK__WPCOM_JSON_API_BASE' ),
			'rest',
			'1.1',
			$endpoint
		);

		$request_args = array_merge( $args, array( 'body' => $body ) );

		$result = Client::_wp_remote_request( $url, $request_args );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		$response = $result['http_response'];

		$status = $response->get_status();

		$data = array(
			'code' => $status,
			'data' => json_decode( $response->get_data(), true ),
		);

		return rest_ensure_response(
			new WP_REST_Response( $data, $status )
		);
	}

	/**
	 * Update the a poster image via the WPCOM REST API.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function videopress_block_update_poster( $request ) {
		if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
			$blog_id = get_current_blog_id();
		} else {
			$blog_id = Jetpack_Options::get_option( 'id' );
		}

		$token       = $this->videopress_get_upload_token( $blog_id );
		$video_guid  = $request->get_param( 'video_guid' );
		$json_params = $request->get_json_params();

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'content-type'  => 'application/json',
				'Authorization' => 'X_UPLOAD_TOKEN token="' . $token . '" blog_id="' . $blog_id . '"',
			),
		);

		return $this->wpcom_poster_request(
			$video_guid,
			$args,
			wp_json_encode( $json_params )
		);
	}

	/**
	 * Retrieves a poster image via the WPCOM REST API.
	 *
	 * @param WP_REST_Request $request the request object.
	 * @return object|WP_Error Success object or WP_Error with error details.
	 */
	public function videopress_block_get_poster( $request ) {
		$video_guid = $request->get_param( 'video_guid' );
		$jwt        = $this->request_jwt_from_wpcom( $video_guid );

		$args = array(
			'method' => 'GET',
		);

		return $this->wpcom_poster_request(
			$video_guid,
			$args,
			null,
			'metadata_token=' . $jwt
		);
	}

	/**
	 * Updates attachment meta and video metadata via the WPCOM REST API.
	 *
	 * @param WP_REST_Request $request the request object.
	 * @return object|WP_Error Success object or WP_Error with error details.
	 */
	public function videopress_block_update_meta( $request ) {
		$json_params = $request->get_json_params();
		$post_id     = $json_params['id'];

		if ( ! defined( 'IS_WPCOM' ) || ! IS_WPCOM ) {
			$guid = get_post_meta( $post_id, 'videopress_guid', true );
		} else {
			$blog_id = get_current_blog_id();
			$info    = video_get_info_by_blogpostid( $blog_id, $post_id );
			$guid    = $info->guid;
		}

		if ( ! $guid ) {
			return rest_ensure_response(
				new WP_Error(
					'error',
					__( 'This attachment cannot be updated yet.', 'jetpack-videopress-pkg' )
				)
			);
		}

		$video_request_params = $json_params;
		unset( $video_request_params['id'] );
		$video_request_params['guid'] = $guid;

		$endpoint = 'videos';
		$args     = array(
			'method'  => 'POST',
			'headers' => array( 'content-type' => 'application/json' ),
		);

		$result = Client::wpcom_json_api_request_as_blog(
			$endpoint,
			'2',
			$args,
			wp_json_encode( $video_request_params ),
			'wpcom'
		);

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		$response_body = json_decode( wp_remote_retrieve_body( $result ) );
		if ( is_bool( $response_body ) && $response_body ) {
			/*
			 * Title and description of the video are not stored as metadata on the attachment,
			 * but as post_content and post_title on the attachment's post object.
			 * We need to update those fields here, too.
			 */
			$post_title = isset( $json_params['title'] ) ? sanitize_text_field( $json_params['title'] ) : null;
			if ( $post_title ) {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $post_title,
					)
				);
			}

			$post_content = isset( $json_params['description'] ) ? sanitize_textarea_field( $json_params['description'] ) : null;
			if ( $post_content ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $post_content,
					)
				);
			}

			// VideoPress data is stored in attachment meta for Jetpack sites, but not on wpcom.
			if ( ! defined( 'IS_WPCOM' ) || ! IS_WPCOM ) {
				$meta               = wp_get_attachment_metadata( $post_id );
				$should_update_meta = false;

				if ( ! $meta ) {
					return rest_ensure_response(
						new WP_Error(
							'error',
							__( 'Attachment meta was not found.', 'jetpack-videopress-pkg' )
						)
					);
				}

				if ( isset( $json_params['display_embed'] ) && isset( $meta['videopress']['display_embed'] ) ) {
					$meta['videopress']['display_embed'] = $json_params['display_embed'];
					$should_update_meta                  = true;
				}

				if ( isset( $json_params['rating'] ) && isset( $meta['videopress']['rating'] ) && videopress_is_valid_video_rating( $json_params['rating'] ) ) {
					$meta['videopress']['rating'] = $json_params['rating'];
					$should_update_meta           = true;
				}

				if ( isset( $post_title ) ) {
					$meta['videopress']['title'] = $post_title;
					$should_update_meta          = true;
				}

				if ( isset( $json_params['description'] ) ) {
					$meta['videopress']['description'] = $post_content;
					$should_update_meta                = true;
				}

				if ( isset( $json_params['allow_download'] ) ) {
					$allow_download = (bool) $json_params['allow_download'];
					if ( ! isset( $meta['videopress']['allow_download'] ) || $meta['videopress']['allow_download'] !== $allow_download ) {
						$meta['videopress']['allow_download'] = $allow_download;
						$should_update_meta                   = true;
					}
				}

				if ( isset( $json_params['privacy_setting'] ) ) {
					$privacy_setting = $json_params['privacy_setting'];
					if ( ! isset( $meta['videopress']['privacy_setting'] ) || $meta['videopress']['privacy_setting'] !== $privacy_setting ) {
						$meta['videopress']['privacy_setting'] = $privacy_setting;
						$should_update_meta                    = true;
					}
				}

				if ( $should_update_meta ) {
					wp_update_attachment_metadata( $post_id, $meta );
				}
			}

			return rest_ensure_response(
				array(
					'code'    => 'success',
					'message' => __( 'Video meta updated successfully.', 'jetpack-videopress-pkg' ),
					'data'    => 200,
				)
			);
		} else {
			return rest_ensure_response(
				new WP_Error(
					$response_body->code,
					$response_body->message,
					$response_body->data
				)
			);
		}
	}
}

if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
	wpcom_rest_api_v2_load_plugin( 'Automattic\Jetpack\VideoPress\WPCOM_REST_API_V2_Endpoint_VideoPress' );
}
