<?php
/*
Plugin Name: KARASU External Media
Description: 外部の画像をURL指定でメディアライブラリに登録する。
Version: 1.0
*/
add_action('admin_menu', function() {
	add_submenu_page('upload.php', 'KARASU External Media', 'KARASU External Media', 'edit_posts', 'karasu_external_media', function() {
		echo '<h2>Google Photo画像の追加</h2>';
		echo '<a href="https://photos.google.com/" target="_blank">Google Photoを開く</a>';

		if($_SERVER["REQUEST_METHOD"] == "POST"){
			if($_REQUEST['g_ph']) google_photo_insert($_REQUEST['g_ph']);
		}

		echo 'v1.0.0';
		echo '<form method="post">';
		echo '<h3><span class="dashicons dashicons-format-image"></span>&nbsp;Google Photo画像の共有URL</h3>'
			.'<table class="form-table">'
			.'<tr>'
			.'<th>共有リンク'
			.'<p class="description">Google Photoの共有リンク</p></th>'
			.'<td><input type="text" name="g_ph" style="width:100%;"'
			.' placeholder="https://photos.app.goo.gl/xxxxxxxxxxxxx" value=""/></td>'
			.'</tr>'
			.'</table>';

		submit_button();
		echo '</form>';

	}, 'dashicons-format-image');
});

/**************************************
Google Photoのアドレスをメディアとして登録
*/
function google_photo_insert($direct_link_url) {
	$data = google_photo_get_data($direct_link_url);
	if($data !== false) {
		$id = wp_insert_attachment([
			'post_title' => '[external media]'.$data['filename']
		  , 'post_name' => $data['filename']
		  , 'post_content' => ''
		  , 'post_status' => 'inherit'
		  , 'post_mime_type' => $data['content-type']
		  , 'guid' => $data['base_url']
		], $data['filename'], 0);

		if($id != 0) {
			$meta_info = google_photo_create_meta_info($data);
			if(is_array($meta_info)) {
				wp_update_attachment_metadata($id, $meta_info);
				echo '<div id="settings_updated" class="updated notice is-dismissible">'
					.'<p><strong>設定を保存しました。</strong><br/>'
					.'<img src="'.wp_get_attachment_image_src($id)[0].'"/>'
					.'</p></div>';
				return;
			}
		}
	}

	echo '<div class="notice notice-error settings-error is-dismissible">'
		.'<p><strong>登録に失敗しました。</strong></p>'
		.'</div>';
}

/**************************************
Google Photoの直リンクIDを取得して登録
$direct_link_url: 共有リンクのURL(https://photos.app.goo.gl/xxxxxxxxxxxxx)
*/
function google_photo_get_data($direct_link_url) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $direct_link_url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 15);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Locationをたどる
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);        // 最大何回リダイレクトをたどるか
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);    // リダイレクトの際にヘッダのRefererを自動的に追加させる
	curl_setopt($ch, CURLOPT_HEADER, true);         // ヘッダも出力したい場合
	$result = curl_exec($ch);

	if($result !== false) {
		if(preg_match('/<meta property="og:image"'
		.' content="https:\/\/lh3\.googleusercontent\.com\/pw\/([^=]+)=/'
		, $result, $match)){
			$data = [
				  'ID' => $match[1]
				, 'base_url' => 'https://lh3.googleusercontent.com/pw/'.$match[1]
			];
			curl_setopt($ch, CURLOPT_URL, $data['base_url'].'=s0');
			$result = curl_exec($ch);
			$info = curl_getinfo($ch);
			$header = substr($result, 0, $info["header_size"]);
			$image = substr($result, -$info["download_content_length"]);

			$data += ['filesize' => $info["download_content_length"]];

			if(preg_match('/\ncontent-type: *(.+)\r/iu', $header, $m) == 1) {
				$data += ['content-type' => $m[1]];
			} else {
				return false;
			}
			if(preg_match('/\ncontent-disposition:.*filename="(.+)"/iu', $header, $m) > 0) {
				$data += ['filename' => $m[1]];
				if(preg_match('/\ncontent-disposition:.*filename\*=UTF-8\'\'(.+)\r/iu', $header, $m) > 0) {
					$data['filename'] = urldecode($m[1]);
				}
			} else {
				return false;
			}
			$image = imagecreatefromstring($image);
			if($image !== false) {
				$data += ['width' => imagesx($image)];
				$data += ['height' => imagesy($image)];
				imagedestroy($image);
			}

			return $data;
		}
	}

	return false;
}

/**************************************
メタ情報の作成
*/
function google_photo_create_meta_info($data) {
	$result = [
		  'width' => $data['width']
		, 'height' => $data['height']
		, 'file' => $data['base_url'].'=d'
		, 'filesize' => $data['filesize']
		, 'sizes' => []
		, 'image_meta' => [
			  'aperture' => '0'
			, 'credit' => ''
			, 'camera' => ''
			, 'caption' => ''
			, 'created_timestamp' => '0'
			, 'copyright' => ''
			, 'focal_length' => '0'
			, 'iso' => '0'
			, 'shutter_speed' => '0'
			, 'title' => ''
			, 'orientation' => '0'
			, 'keywords' => []
		  ]
		, 'external-media' => true
	];

	foreach(['cthumbnail', '_medium', '_large'] as $_size_name) {
		$size_name = substr($_size_name, 1);
		$is_crop = (substr($_size_name, 0, 1) == 'c') ? true : false;
		$width = intval(get_option("{$size_name}_size_w"));
		$height = intval(get_option("{$size_name}_size_h"));
		$pw = $width / $data['width'];
		$ph = $height / $data['height'];
		if($pw < 1 || $ph < 1) {
			if($is_crop) {
				$result['sizes'] += [
					$size_name => [
					  'file' => $data['ID'].'=w'.$width.'-h'.$height.'-c'
					, 'width'  => $width
					, 'height' => $height
					, 'mime-type' => $data['content-type']
					]
				];
			} else {
				$result['sizes'] += [
					$size_name => [
					  'file' => $data['ID'].'='.(($pw <= $ph) ? 'w'.$width : 'h'.$height)
					, 'width'  => ($pw <= $ph) ? $width : (int)($data['width'] * $ph)
					, 'height' => ($pw <= $ph) ? (int)($data['height'] * $pw) : $height
					, 'mime-type' => $data['content-type']
					]
				];
			}
		}
	}

	return $result;
}

/*
wp_get_attachment_url
*/
add_filter('wp_get_attachment_url', function($url, $id) {
	$post_meta = get_post_meta($id, '_wp_attachment_metadata')[0];
	if(isset($post_meta['external-media'])){
		$url = $post_meta['file'];
	}

	return $url;
}, 10, 2);

/*
wp_get_attachment_image_src
*/
// add_filter('wp_get_attachment_image_src', function($image, $attachment_id, $size, $icon) {
// }, 10, 4);

// /*
// image_downsize
// */
// add_filter('image_downsize', function($downsize, $id, $size) {
// }, 10, 3);

?>
