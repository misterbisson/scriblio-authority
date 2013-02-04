(function($){
	tinymce.create('tinymce.plugins.easyterms', {
		init: function(ed, url) {
			$.each( scriblio_easyterms, function(taxonomy, shortcode) {
				var mceCommand = 'mce' + taxonomy + 'Term',
					shortcode_open = '[' + shortcode + ']',
					shortcode_close = '[/' + shortcode + ']';

				ed.addCommand(mceCommand, function(){
					  var content = tinyMCE.activeEditor.selection.getContent();
					  tinyMCE.activeEditor.selection.setContent( shortcode_open + content + shortcode_close );
				});

				ed.addButton( taxonomy, {
					title: taxonomy,
					cmd: mceCommand
				});
			});
		}
	});

	tinymce.PluginManager.add( 'easyterms', tinymce.plugins.easyterms );
})(jQuery);
