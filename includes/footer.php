    <footer class="footer">
      <div class="footer-body gap-2">
      </div>
    </footer>
  </main>
  <script src="https://optimusinfinity.com/assets/js/core/libs.min.js"></script>
  <script src="https://optimusinfinity.com/assets/js/core/external.min.js"></script>
  <script src="https://optimusinfinity.com/assets/js/fslightbox.js"></script>
  <script src="https://optimusinfinity.com/assets/js/coinex.js"></script>
  <script>
    $(document).ready(function () {
      $('#copy_btn').click(function () {
        var textToCopy = "https://optimusinfinity.com/registration_new/<?php echo bin2hex($user['id'] ?? '0'); ?>";
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
