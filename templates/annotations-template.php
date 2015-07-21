<?php 
/* Template Name: Annotations Template */
get_header(); 
?>
	
	<div id="primary" class="content-area">
		<main id="main" class="site-main" role="main">
			<article class="page type-page status-publish hentry">
				<div class="entry-content">
					<?php $Annotation_Plugin->getAnnotations(); ?>
				</div>
			</article>
		</main><!-- .site-main -->
	</div><!-- .content-area -->

<?php 
get_footer(); 
?>