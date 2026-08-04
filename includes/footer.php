    <footer class="footer">
      <div class="footer-body gap-2">
      </div>
    </footer>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://optimusinfinity.com/assets/js/core/libs.min.js"></script>
  <script src="https://optimusinfinity.com/assets/js/core/external.min.js"></script>
  <script src="https://optimusinfinity.com/assets/js/fslightbox.js"></script>
  <script src="https://optimusinfinity.com/assets/js/coinex.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var copyBtn = document.getElementById('copy_btn');
      if (copyBtn) {
        copyBtn.addEventListener('click', function () {
          <?php
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $dir = dirname($_SERVER['PHP_SELF']);
            $baseUrl = $protocol . "://" . $host . $dir;
            $baseUrl = rtrim($baseUrl, '/\\');
          ?>
          var textToCopy = "<?php echo $baseUrl; ?>/registration_new.php?ref=<?php echo !empty($user['mid']) ? $user['mid'] : ($user['id'] ?? '0'); ?>";

          // Use modern clipboard API first, fallback to textarea method
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(function () {
              showCopiedFeedback();
            }).catch(function () {
              fallbackCopy(textToCopy);
            });
          } else {
            fallbackCopy(textToCopy);
          }

          function fallbackCopy(text) {
            var tempTextarea = document.createElement("textarea");
            tempTextarea.value = text;
            tempTextarea.style.position = "fixed";  // Avoid scrolling to bottom
            document.body.appendChild(tempTextarea);
            tempTextarea.select();
            try {
              document.execCommand("copy");
              showCopiedFeedback();
            } catch (err) {
              console.error("Fallback copy failed", err);
            }
            document.body.removeChild(tempTextarea);
          }

          function showCopiedFeedback() {
            copyBtn.innerHTML = "Copied!";
            setTimeout(function () {
              copyBtn.innerHTML = "Referral Link";
            }, 2000);
          }
        });
      }
    });
  </script>
</body>
</html>
