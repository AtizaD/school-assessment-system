<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
?>
        </div>
    </div>
</div>
<!-- Load jQuery first -->
<script src="<?php echo BASE_URL; ?>/assets/js/jquery.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/popper.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/bootstrap.min.js"></script>
<!-- Load KaTeX JS before auto-render -->
<script src="<?php echo BASE_URL; ?>/assets/maths/katex.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/maths/contrib/auto-render.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/mathquill/mathquill.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/math-editor.js"></script>

<!-- Add the auto-render initialization script -->
<script>
    document.addEventListener("DOMContentLoaded", function() {
        renderMathInElement(document.body, {
            // KaTeX options
            delimiters: [
                {left: '$$', right: '$$', display: true},
                {left: '$', right: '$', display: false},
                {left: '\\(', right: '\\)', display: false},
                {left: '\\[', right: '\\]', display: true}
            ],
            throwOnError: false
        });
    });
</script>
</body>
</html>