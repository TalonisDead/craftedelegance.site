<?php
/**
 * The template for displaying the footer.
 *
 * @package          Flatsome\Templates
 * @flatsome-version 3.16.0
 */

global $flatsome_opt;
?>

</main>

<footer id="footer" class="footer-wrapper">

	<?php do_action('flatsome_footer'); ?>

</footer>

</div>

<?php wp_footer(); ?>

</body>
</html>
<script type="text/javascript">
    jQuery(document).ready(function($){
        function triggerPopup() {
            // Sử dụng Popup Maker để mở popup
            PUM.open(964); // Thay "123" bằng ID của popup bạn đã tạo
        }

        // Hiển thị popup ngay lập tức khi vào trang
        triggerPopup();

        // Thiết lập vòng lặp để hiển thị popup cứ sau 100 giây
        setInterval(function() {
            triggerPopup();
        }, 100000); // 100000ms = 100 giây
    });
</script>