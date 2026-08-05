(function ($) {
  $(function () {

    $('.yrc-reels-wrap').each(function () {
      var $wrap = $(this);
      var $carousel = $wrap.find('.yrc-reels-carousel');

      // ---------------- Main carousel ----------------
      $carousel.not('.slick-initialized').slick({
        slidesToShow: 5,
        slidesToScroll: 2,
        arrows: false,
        infinite: true,
        speed: 350,
        cssEase: 'ease',
        swipeToSlide: true,
        touchThreshold: 10,
        waitForAnimate: false,
        adaptiveHeight: false,
        responsive: [
          { breakpoint: 1400, settings: { slidesToShow: 5, slidesToScroll: 2 } },
          { breakpoint: 1200, settings: { slidesToShow: 4, slidesToScroll: 2 } },
          { breakpoint: 992, settings: { slidesToShow: 3, slidesToScroll: 2 } },
          { breakpoint: 768, settings: { slidesToShow: 2, slidesToScroll: 1 } },
          { breakpoint: 480, settings: { slidesToShow: 1, slidesToScroll: 1 } }
        ]
      });

      // Custom arrows for carousel
      $wrap.on('click', '.yrc-prev', function () { $carousel.slick('slickPrev'); });
      $wrap.on('click', '.yrc-next', function () { $carousel.slick('slickNext'); });

      // ---------------- Lightbox ----------------
      var $lightbox = $('#yrc-reels-lightbox');
      var $lbInner = $lightbox.find('.yrc-lightbox-slider');
      var lbSlick = null;
      var clickedIndex = 0;

      // Build all slides (empty iframes initially)
      function buildLBSlides() {
        $lbInner.empty();

        var slickObj = $carousel.slick('getSlick');
        if (!slickObj) return;

        var $realSlides = slickObj.$slides.filter(':not(.slick-cloned)');

        $realSlides.each(function () {
          // FIX: read video-id directly from .yrc-reel-item
          var vid = $(this).data('video-id') || $(this).find('.yrc-reel-item').data('video-id') || '';
          var slide = $('<div class="yrc-lb-slide" data-video-id="' + vid + '"><div class="yrc-iframe-wrap"></div></div>');
          $lbInner.append(slide);
        });
      }


      // YouTube embed URL
      function embedURL(id, opts) {
        var p = new URLSearchParams({
          autoplay: opts && opts.autoplay ? 1 : 0,
          mute: opts && opts.mute ? 1 : 0,
          rel: 0,
          modestbranding: 1,
          playsinline: 1,
          controls: 1,
          enablejsapi: 1,
          origin: window.location.origin,
          loop: 1,
          playlist: id
        });
        return 'https://www.youtube.com/embed/' + id + '?' + p.toString();
      }

      // Load only current, prev, next videos
      function loadLBSlidesAround(index) {
        var $slides = $lbInner.find('.yrc-lb-slide');
        if (!$slides.length) return;

        $slides.each(function (i) {
          var $s = $(this);
          var $frameWrap = $s.find('.yrc-iframe-wrap');
          var id = $s.data('video-id');

          if (i === index) {
            // Center = autoplay, unmuted
            if ($frameWrap.children('iframe').length === 0 && id) {
              $frameWrap.html('<iframe src="' + embedURL(id, { autoplay: true, mute: true }) + '" allow="autoplay; encrypted-media" allowfullscreen frameborder="0"></iframe>');
            }
          } else if (i === index - 1 || i === index + 1) {
            // Neighbors = muted
            if ($frameWrap.children('iframe').length === 0 && id) {
              $frameWrap.html('<iframe src="' + embedURL(id, { autoplay: false, mute: true }) + '" allow="autoplay; encrypted-media" allowfullscreen frameborder="0"></iframe>');
            }
          } else {
            $frameWrap.empty(); // keep others empty
          }
        });
      }

      // Open lightbox centered on clicked index
      function openLightbox(startIndex) {
        buildLBSlides();

        $lbInner.not('.slick-initialized').slick({
          slidesToShow: 3,
          slidesToScroll: 1,
          arrows: false,
          dots: false,
          infinite: false,
          centerMode: true,
          centerPadding: '12%',
          initialSlide: startIndex || 0,
          adaptiveHeight: true
        });

        lbSlick = $lbInner;

        loadLBSlidesAround(startIndex);

        lbSlick.on('afterChange.yrc', function (e, slick, current) {
          loadLBSlidesAround(current);
        });

        $lightbox.addClass('open').attr('aria-hidden', 'false');
        $('body').addClass('yrc-lb-open');

        setTimeout(function () {
          if (lbSlick && lbSlick.hasClass('slick-initialized')) {
            lbSlick.slick('setPosition');
          }
        }, 50);
      }

      // Close lightbox
      function closeLightbox() {
        if (lbSlick && lbSlick.hasClass('slick-initialized')) {
          $lbInner.find('.yrc-iframe-wrap').empty();
          try { lbSlick.slick('unslick'); } catch (e) {}
        }
        $lightbox.removeClass('open').attr('aria-hidden', 'true');
        $('body').removeClass('yrc-lb-open');
        lbSlick = null;
        $lbInner.empty();
      }

      // Open on thumbnail/play button click
      $wrap.on('click', '.yrc-play-button', function (e) {
        e.preventDefault();
        var $slide = $(this).closest('.slick-slide');
        var rawIdx = parseInt($slide.attr('data-slick-index'), 10) || 0;
        var slickObj = $carousel.slick('getSlick');
        var realCount = slickObj.$slides.filter(':not(.slick-cloned)').length;

        clickedIndex = ((rawIdx % realCount) + realCount) % realCount;
        openLightbox(clickedIndex);
      });

      // Controls
      $lightbox.on('click', '.yrc-lightbox-close', function () { closeLightbox(); });
      $lightbox.on('click', '.yrc-lightbox-prev', function () { if (lbSlick) lbSlick.slick('slickPrev'); });
      $lightbox.on('click', '.yrc-lightbox-next', function () { if (lbSlick) lbSlick.slick('slickNext'); });

      // Close on backdrop
      $lightbox.on('click', function (e) {
        if ($(e.target).is($lightbox)) closeLightbox();
      });

      // Keyboard
      $(document).on('keydown', function (e) {
        if (!$lightbox.hasClass('open')) return;
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft' && lbSlick) lbSlick.slick('slickPrev');
        if (e.key === 'ArrowRight' && lbSlick) lbSlick.slick('slickNext');
      });

    }); // each wrap
  });
})(jQuery);
