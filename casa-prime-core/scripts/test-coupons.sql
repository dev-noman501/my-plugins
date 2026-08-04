-- Idempotent fallback seeder for LocalWP/WP-CLI environments.
INSERT INTO wp_posts
	(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type)
SELECT 1, NOW(), UTC_TIMESTAMP(), '', 'SAVE10', '10% off the cart.', 'publish', 'closed', 'closed', 'save10', '', '', NOW(), UTC_TIMESTAMP(), '', 'shop_coupon'
WHERE NOT EXISTS (SELECT 1 FROM wp_posts WHERE post_type='shop_coupon' AND post_name='save10');
INSERT INTO wp_posts
	(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type)
SELECT 1, NOW(), UTC_TIMESTAMP(), '', 'WELCOME15', '15% off orders of $50 or more.', 'publish', 'closed', 'closed', 'welcome15', '', '', NOW(), UTC_TIMESTAMP(), '', 'shop_coupon'
WHERE NOT EXISTS (SELECT 1 FROM wp_posts WHERE post_type='shop_coupon' AND post_name='welcome15');
INSERT INTO wp_posts
	(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type)
SELECT 1, NOW(), UTC_TIMESTAMP(), '', 'TAKE5', '$5 off orders of $25 or more.', 'publish', 'closed', 'closed', 'take5', '', '', NOW(), UTC_TIMESTAMP(), '', 'shop_coupon'
WHERE NOT EXISTS (SELECT 1 FROM wp_posts WHERE post_type='shop_coupon' AND post_name='take5');
INSERT INTO wp_posts
	(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type)
SELECT 1, NOW(), UTC_TIMESTAMP(), '', 'PRIME20', '$20 off orders of $100 or more.', 'publish', 'closed', 'closed', 'prime20', '', '', NOW(), UTC_TIMESTAMP(), '', 'shop_coupon'
WHERE NOT EXISTS (SELECT 1 FROM wp_posts WHERE post_type='shop_coupon' AND post_name='prime20');

DELETE pm FROM wp_postmeta pm
JOIN wp_posts p ON p.ID=pm.post_id
WHERE p.post_type='shop_coupon' AND p.post_name IN ('save10','welcome15','take5','prime20')
AND pm.meta_key IN ('discount_type','coupon_amount','minimum_amount','individual_use','usage_limit','usage_limit_per_user','usage_count');

INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT ID, 'discount_type', IF(post_name IN ('save10','welcome15'),'percent','fixed_cart') FROM wp_posts WHERE post_type='shop_coupon' AND post_name IN ('save10','welcome15','take5','prime20');
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT ID, 'coupon_amount', CASE post_name WHEN 'save10' THEN '10' WHEN 'welcome15' THEN '15' WHEN 'take5' THEN '5' ELSE '20' END FROM wp_posts WHERE post_type='shop_coupon' AND post_name IN ('save10','welcome15','take5','prime20');
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT ID, 'minimum_amount', CASE post_name WHEN 'welcome15' THEN '50' WHEN 'take5' THEN '25' WHEN 'prime20' THEN '100' ELSE '' END FROM wp_posts WHERE post_type='shop_coupon' AND post_name IN ('save10','welcome15','take5','prime20');
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT ID, 'individual_use', 'no' FROM wp_posts WHERE post_type='shop_coupon' AND post_name IN ('save10','welcome15','take5','prime20');
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT ID, 'usage_count', '0' FROM wp_posts WHERE post_type='shop_coupon' AND post_name IN ('save10','welcome15','take5','prime20');
