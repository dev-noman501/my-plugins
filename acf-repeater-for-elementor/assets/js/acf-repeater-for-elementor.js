(function ($) {
	'use strict';

	function initCarousel($scope) {
		var $carousel = $scope.find('.arfe-carousel').first();
		if (!$carousel.length || typeof Swiper === 'undefined') {
			return;
		}

		var options = {};
		try {
			options = JSON.parse($carousel.attr('data-swiper-options') || '{}');
		} catch (e) {
			options = {};
		}

		var swiperConfig = {
			slidesPerView: options.slidesPerView_mobile || 1,
			spaceBetween: options.spaceBetween_mobile || 0,
			slidesPerGroup: options.slidesPerGroup || 1,
			loop: !!options.loop,
			speed: options.speed || 500,
			autoHeight: !!options.autoHeight,
			observer: true,
			observeParents: true,
			breakpoints: {
				768: {
					slidesPerView: options.slidesPerView_tablet || 2,
					spaceBetween: options.spaceBetween_tablet || 0,
				},
				1025: {
					slidesPerView: options.slidesPerView || 3,
					spaceBetween: options.spaceBetween || 0,
				},
			},
		};

		if (options.autoplay) {
			swiperConfig.autoplay = options.autoplay;
		}

		if (options.navigation) {
			swiperConfig.navigation = {
				nextEl: $carousel.find('.arfe-nav-next')[0],
				prevEl: $carousel.find('.arfe-nav-prev')[0],
			};
		}

		if (options.pagination) {
			swiperConfig.pagination = {
				el: $carousel.find('.swiper-pagination')[0],
				clickable: true,
			};
		}

		// Avoid double-initializing the same slider (e.g. on Elementor editor re-render).
		if ($carousel[0].swiper) {
			$carousel[0].swiper.destroy(true, true);
		}

		new Swiper($carousel[0], swiperConfig);
	}

	$(window).on('elementor/frontend/init', function () {
		elementorFrontend.hooks.addAction('frontend/element_ready/arfe_repeater_widget.default', initCarousel);
	});

	// Fallback for non-Elementor-managed contexts (e.g. direct page load without the hook).
	$(function () {
		$('.elementor-widget-arfe_repeater_widget').each(function () {
			initCarousel($(this));
		});
	});
})(jQuery);
