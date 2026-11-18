<?php
// KutBlog - Gallery & Carousel Core Module

function kutblog_gallery_assets(): array {
    $css = <<<CSS
<style id="kutblog-gallery-css">
/* KutBlog Gallery - Styles */
.kutblog-gallery {
  margin: 2rem auto;
  width: 100%;
  max-width: 100%;
  position: relative;
  box-sizing: border-box;
}
.kutblog-gallery * {
  box-sizing: border-box;
}

/* Grid Layout */
.kutblog-gallery-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(var(--gallery-cols, 3), 1fr);
  width: 100%;
}
@media (max-width: 768px) {
  .kutblog-gallery-grid {
    grid-template-columns: repeat(min(var(--gallery-cols, 3), 2), 1fr) !important;
    gap: 10px;
  }
}
@media (max-width: 480px) {
  .kutblog-gallery-grid {
    grid-template-columns: 1fr !important;
    gap: 10px;
  }
}

.kutblog-gallery-item {
  margin: 0;
  position: relative;
  overflow: hidden;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.04);
  cursor: pointer;
  display: flex;
  flex-direction: column;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.kutblog-gallery-item:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}
.kutblog-gallery-grid .kutblog-gallery-item img {
  width: 100%;
  height: 240px;
  object-fit: cover;
  display: block;
  border-radius: 10px 10px 0 0;
  transition: transform 0.3s ease;
}
.kutblog-gallery-grid .kutblog-gallery-item:only-child img,
.kutblog-gallery-grid .kutblog-gallery-item:last-child:first-child img {
  border-radius: 10px;
  height: auto;
  max-height: 500px;
}
.kutblog-gallery-item:hover img {
  transform: scale(1.02);
}
.kutblog-gallery-item figcaption {
  padding: 8px 12px;
  font-size: 0.85rem;
  color: var(--text-muted, #71717a);
  background: var(--surface, rgba(255, 255, 255, 0.85));
  text-align: center;
  border-radius: 0 0 10px 10px;
  line-height: 1.35;
}

/* Carousel Layout */
.kutblog-gallery-carousel {
  position: relative;
  overflow: hidden;
  border-radius: 12px;
  background: #090a0f;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
  user-select: none;
}
.kutblog-carousel-viewport {
  overflow: hidden;
  width: 100%;
}
.kutblog-carousel-track {
  display: flex;
  transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
  will-change: transform;
}
.kutblog-gallery-carousel .kutblog-gallery-item {
  flex: 0 0 100%;
  max-width: 100%;
  border-radius: 0;
  background: transparent;
  align-items: center;
  justify-content: center;
}
.kutblog-gallery-carousel .kutblog-gallery-item:hover {
  transform: none;
  box-shadow: none;
}
.kutblog-gallery-carousel .kutblog-gallery-item img {
  width: 100%;
  max-height: 520px;
  object-fit: contain;
  display: block;
  margin: 0 auto;
}
.kutblog-gallery-carousel .kutblog-gallery-item figcaption {
  width: 100%;
  background: rgba(0, 0, 0, 0.75);
  color: #f4f4f5;
  border-radius: 0;
  padding: 8px 16px;
  font-size: 0.88rem;
}

/* Carousel Nav Buttons */
.kutblog-carousel-btn {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  background: rgba(20, 22, 28, 0.75);
  color: #ffffff;
  border: 1px solid rgba(255, 255, 255, 0.25);
  width: 44px;
  height: 44px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  line-height: 1;
  z-index: 10;
  backdrop-filter: blur(8px);
  transition: all 0.2s ease;
}
.kutblog-carousel-btn:hover {
  background: var(--accent, #6366f1);
  transform: translateY(-50%) scale(1.1);
}
.kutblog-carousel-btn.prev { left: 14px; }
.kutblog-carousel-btn.next { right: 14px; }
@media (max-width: 480px) {
  .kutblog-carousel-btn {
    width: 36px;
    height: 36px;
    font-size: 20px;
  }
  .kutblog-carousel-btn.prev { left: 8px; }
  .kutblog-carousel-btn.next { right: 8px; }
}

/* Carousel Dots */
.kutblog-carousel-dots {
  position: absolute;
  bottom: 12px;
  left: 0;
  right: 0;
  display: flex;
  justify-content: center;
  gap: 6px;
  z-index: 10;
}
.kutblog-carousel-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.4);
  border: none;
  padding: 0;
  cursor: pointer;
  transition: all 0.25s ease;
}
.kutblog-carousel-dot.active {
  background: #ffffff;
  width: 22px;
  border-radius: 4px;
}

/* Lightbox Modal */
.kutblog-lightbox {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.92);
  z-index: 999999;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
  user-select: none;
  backdrop-filter: blur(6px);
}
.kutblog-lightbox.active {
  display: flex;
}
.kutblog-lightbox-img {
  max-width: 92vw;
  max-height: 86vh;
  object-fit: contain;
  border-radius: 6px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.6);
  transition: opacity 0.2s ease;
}
.kutblog-lightbox-caption {
  position: absolute;
  bottom: 20px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(15, 17, 23, 0.85);
  color: #f4f4f5;
  padding: 6px 18px;
  border-radius: 20px;
  font-size: 0.88rem;
  max-width: 80vw;
  text-align: center;
  border: 1px solid rgba(255,255,255,0.15);
}
.kutblog-lightbox-counter {
  position: absolute;
  top: 24px;
  left: 24px;
  background: rgba(15, 17, 23, 0.8);
  color: #f4f4f5;
  padding: 4px 14px;
  border-radius: 20px;
  font-size: 0.82rem;
  font-weight: 600;
  border: 1px solid rgba(255,255,255,0.15);
}
.kutblog-lightbox-close {
  position: absolute;
  top: 20px;
  right: 24px;
  background: transparent;
  border: none;
  color: #ffffff;
  font-size: 32px;
  cursor: pointer;
  line-height: 1;
  padding: 4px;
  transition: transform 0.2s, color 0.2s;
}
.kutblog-lightbox-close:hover {
  color: var(--accent, #6366f1);
  transform: scale(1.15);
}
.kutblog-lightbox-nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  background: rgba(20, 22, 28, 0.7);
  color: #ffffff;
  border: 1px solid rgba(255,255,255,0.25);
  width: 50px;
  height: 50px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  line-height: 1;
  backdrop-filter: blur(8px);
  transition: all 0.2s;
}
.kutblog-lightbox-nav:hover {
  background: var(--accent, #6366f1);
  transform: translateY(-50%) scale(1.1);
}
.kutblog-lightbox-nav.prev { left: 24px; }
.kutblog-lightbox-nav.next { right: 24px; }
@media (max-width: 480px) {
  .kutblog-lightbox-nav {
    width: 38px;
    height: 38px;
    font-size: 22px;
  }
  .kutblog-lightbox-nav.prev { left: 10px; }
  .kutblog-lightbox-nav.next { right: 10px; }
}
</style>
CSS;

    $js = <<<JS
<script id="kutblog-gallery-js">
(function() {
  function initKutBlogGalleries() {
    // 1. Iniciar Carruseles
    document.querySelectorAll('.kutblog-gallery[data-layout="carousel"]').forEach(function(gallery) {
      if (gallery._carouselInited) return;
      gallery._carouselInited = true;

      const track = gallery.querySelector('.kutblog-carousel-track');
      const items = gallery.querySelectorAll('.kutblog-carousel-track > .kutblog-gallery-item');
      const prevBtn = gallery.querySelector('.kutblog-carousel-btn.prev');
      const nextBtn = gallery.querySelector('.kutblog-carousel-btn.next');
      const dots = gallery.querySelectorAll('.kutblog-carousel-dot');
      if (!track || items.length === 0) return;

      let currentIndex = 0;
      const count = items.length;

      function updateSlide(idx) {
        if (idx < 0) idx = count - 1;
        if (idx >= count) idx = 0;
        currentIndex = idx;
        track.style.transform = 'translateX(-' + (currentIndex * 100) + '%)';
        dots.forEach(function(dot, i) {
          dot.classList.toggle('active', i === currentIndex);
        });
      }

      if (prevBtn) {
        prevBtn.addEventListener('click', function(e) {
          e.stopPropagation();
          updateSlide(currentIndex - 1);
        });
      }
      if (nextBtn) {
        nextBtn.addEventListener('click', function(e) {
          e.stopPropagation();
          updateSlide(currentIndex + 1);
        });
      }
      dots.forEach(function(dot, i) {
        dot.addEventListener('click', function(e) {
          e.stopPropagation();
          updateSlide(i);
        });
      });

      // Touch / Swipe
      let touchStartX = 0;
      let touchEndX = 0;
      track.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
      }, { passive: true });
      track.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        if (touchStartX - touchEndX > 45) {
          updateSlide(currentIndex + 1);
        } else if (touchEndX - touchStartX > 45) {
          updateSlide(currentIndex - 1);
        }
      }, { passive: true });
    });

    // 2. Lightbox global para todas las galerías
    let lightbox = document.getElementById('kutblog-lightbox');
    if (!lightbox) {
      lightbox = document.createElement('div');
      lightbox.id = 'kutblog-lightbox';
      lightbox.className = 'kutblog-lightbox';
      lightbox.innerHTML = `
        <div class="kutblog-lightbox-counter" id="kb-lb-counter"></div>
        <button type="button" class="kutblog-lightbox-close" id="kb-lb-close" aria-label="Cerrar">&times;</button>
        <button type="button" class="kutblog-lightbox-nav prev" id="kb-lb-prev" aria-label="Anterior">&#8249;</button>
        <img class="kutblog-lightbox-img" id="kb-lb-img" src="" alt="">
        <button type="button" class="kutblog-lightbox-nav next" id="kb-lb-next" aria-label="Siguiente">&#8250;</button>
        <div class="kutblog-lightbox-caption" id="kb-lb-caption" style="display:none;"></div>
      `;
      document.body.appendChild(lightbox);
    }

    const lbImg = document.getElementById('kb-lb-img');
    const lbCounter = document.getElementById('kb-lb-counter');
    const lbCaption = document.getElementById('kb-lb-caption');
    const lbClose = document.getElementById('kb-lb-close');
    const lbPrev = document.getElementById('kb-lb-prev');
    const lbNext = document.getElementById('kb-lb-next');

    let activeGalleryItems = [];
    let activeLightboxIndex = 0;

    function renderLightbox() {
      if (activeGalleryItems.length === 0) return;
      if (activeLightboxIndex < 0) activeLightboxIndex = activeGalleryItems.length - 1;
      if (activeLightboxIndex >= activeGalleryItems.length) activeLightboxIndex = 0;

      const item = activeGalleryItems[activeLightboxIndex];
      lbImg.src = item.url;
      lbImg.alt = item.alt || '';
      lbCounter.textContent = (activeLightboxIndex + 1) + ' / ' + activeGalleryItems.length;

      if (item.caption) {
        lbCaption.textContent = item.caption;
        lbCaption.style.display = 'block';
      } else {
        lbCaption.style.display = 'none';
      }

      const multi = activeGalleryItems.length > 1;
      lbPrev.style.display = multi ? 'flex' : 'none';
      lbNext.style.display = multi ? 'flex' : 'none';
      lbCounter.style.display = multi ? 'block' : 'none';
    }

    function openLightbox(items, startIndex) {
      activeGalleryItems = items;
      activeLightboxIndex = startIndex || 0;
      renderLightbox();
      lightbox.classList.add('active');
    }

    function closeLightbox() {
      lightbox.classList.remove('active');
      lbImg.src = '';
      activeGalleryItems = [];
    }

    if (lbClose) lbClose.onclick = closeLightbox;
    lightbox.onclick = function(e) {
      if (e.target === lightbox) closeLightbox();
    };
    if (lbPrev) lbPrev.onclick = function(e) { e.stopPropagation(); activeLightboxIndex--; renderLightbox(); };
    if (lbNext) lbNext.onclick = function(e) { e.stopPropagation(); activeLightboxIndex++; renderLightbox(); };

    document.addEventListener('keydown', function(e) {
      if (!lightbox.classList.contains('active')) return;
      if (e.key === 'Escape') closeLightbox();
      else if (e.key === 'ArrowLeft') { activeLightboxIndex--; renderLightbox(); }
      else if (e.key === 'ArrowRight') { activeLightboxIndex++; renderLightbox(); }
    });

    // Delegación de clic sobre fotos de galerías
    document.querySelectorAll('.kutblog-gallery').forEach(function(gallery) {
      if (gallery._lightboxBound) return;
      gallery._lightboxBound = true;

      gallery.addEventListener('click', function(e) {
        const itemEl = e.target.closest('.kutblog-gallery-item');
        if (!itemEl) return;
        const allItemEls = Array.from(gallery.querySelectorAll('.kutblog-gallery-item'));
        const items = allItemEls.map(function(el) {
          const img = el.querySelector('img');
          const cap = el.querySelector('figcaption');
          return {
            url: img ? img.src : '',
            alt: img ? (img.alt || '') : '',
            caption: cap ? cap.textContent.trim() : ''
          };
        }).filter(function(it) { return it.url; });

        const clickedIdx = Math.max(0, allItemEls.indexOf(itemEl));
        openLightbox(items, clickedIdx);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initKutBlogGalleries);
  } else {
    initKutBlogGalleries();
  }
})();
</script>
JS;

    return ['css' => $css, 'js' => $js];
}
