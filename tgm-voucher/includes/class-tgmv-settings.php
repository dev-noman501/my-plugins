<?php
/**
 * Plugin settings (stored in one option: tgmv_settings).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGMV_Settings {

	public static function defaults() {
		return array(
			'brand_name'   => 'TALABGAR E MADINA',
			'brand_logo'   => '', // empty = bundled TGM logo
			'center_logo'  => '',
			'center_title' => 'Hotel Voucher',
			'prefix'       => 'UB-',
			'show_qr'      => 1,
			'terms_urdu'   => self::default_terms(),
		);
	}

	public static function get() {
		$saved = get_option( 'tgmv_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	public static function brand_logo_url( $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get();
		}
		return $settings['brand_logo'] ? $settings['brand_logo'] : TGMV_URL . 'assets/tgm-logo.png';
	}

	public static function default_terms() {
		return implode( "\n", array(
			'آپ کا ہوٹل اور پیکیج اس دستاویز میں لکھ دیا گیا ہے۔ اس کے مطابق آپ کو رہائش اور دیگر سہولیات فراہم کی جائیں گی۔',
			'سفری سامان حرمین شریفین لے جانا سعودی انتظامیہ کی طرف سے منع ہے، خلاف ورزی کی صورت میں حکومت جرمانہ کر سکتی ہے جس کی ادائیگی آپ کے ذمہ ہوگی۔',
			'نشہ آور اشیاء شرعاً اور قانوناً ممنوع ہیں، سعودیہ میں منشیات لے جانے کی سزا موت ہے۔',
			'حرمین شریفین (مسجد الحرام و مسجد نبویؐ) کے اندر زمین پر گری پڑی چیز (پرس، موبائل فون یا کوئی قیمتی چیز) ہرگز نہ اٹھائیں ورنہ آپ مشکل میں پڑ جائیں گے۔',
			'جدہ ایئرپورٹ پر امیگریشن اور سعودی وزارت و سعودی کمپنی کے انتظام میں 3 سے 5 گھنٹے لگ سکتے ہیں، سعودیہ پہنچ کر اپنے گھر والوں کو خیریت سے پہنچنے کا فون ضرور کر لیں۔',
			'ہوٹل سے چیک آؤٹ ٹائم دوپہر 2 بجے ہے، اس کے بعد اگلی Night چارج ہوگی۔ ہوٹل واؤچر کی چار فوٹو کاپیاں آپ کے پاس ہونی چاہئیں۔',
			'واپسی فلائٹ سے دس گھنٹے پہلے معتمر اپنے سامان سمیت ہوٹل کی ریسپشن پر موجود رہیں۔ واپسی کی فلائٹ کی معلومات معتمر کی اپنی ذمہ داری ہے، نیچے دیے ہوئے نمبروں پر رابطہ کریں۔',
			'سعودی حکومت کے قانون کے مطابق کمپنی کے ہوٹلوں کے علاوہ کسی غیر رجسٹرڈ ہوٹل میں قیام کرنا جرم ہے۔ اگر کوئی معتمر اپنے طور پر نقد ہوٹل کا کمرہ لے گا تو اس کے قانونی اور معاشی نقصان کا خود ذمہ دار ہوگا۔',
		) );
	}

	public static function sanitize( $raw ) {
		$clean = self::defaults();

		$clean['brand_name']   = isset( $raw['brand_name'] ) ? sanitize_text_field( wp_unslash( $raw['brand_name'] ) ) : $clean['brand_name'];
		$clean['brand_logo']   = isset( $raw['brand_logo'] ) ? esc_url_raw( wp_unslash( $raw['brand_logo'] ) ) : '';
		$clean['center_logo']  = isset( $raw['center_logo'] ) ? esc_url_raw( wp_unslash( $raw['center_logo'] ) ) : '';
		$clean['center_title'] = isset( $raw['center_title'] ) ? sanitize_text_field( wp_unslash( $raw['center_title'] ) ) : $clean['center_title'];
		$clean['prefix']       = isset( $raw['prefix'] ) ? sanitize_text_field( wp_unslash( $raw['prefix'] ) ) : $clean['prefix'];
		$clean['show_qr']      = empty( $raw['show_qr'] ) ? 0 : 1;
		$clean['terms_urdu']   = isset( $raw['terms_urdu'] ) ? sanitize_textarea_field( wp_unslash( $raw['terms_urdu'] ) ) : '';

		return $clean;
	}
}
