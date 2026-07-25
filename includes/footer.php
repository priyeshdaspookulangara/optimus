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
    $(document).ready(function () {
      $('#copy_btn').click(function () {
        <?php
          $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
          $host = $_SERVER['HTTP_HOST'];
          $dir = dirname($_SERVER['PHP_SELF']);
          $baseUrl = $protocol . "://" . $host . $dir;
          $baseUrl = rtrim($baseUrl, '/\\');
        ?>
        var textToCopy = "<?php echo $baseUrl; ?>/registration_new.php?id=<?php echo $user['id'] ?? '0'; ?>";
        var tempTextarea = $("<textarea>");
        tempTextarea.val(textToCopy);
        $("body").append(tempTextarea);
        tempTextarea.select();
        document.execCommand("copy");
        tempTextarea.remove();
        $(this).html("Copied");
        setTimeout(function () { $("#copy_btn").html('Referral Link'); }, 2000);
      });
    });
  </script>
</body>
</html>
