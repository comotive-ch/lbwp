;
document.currentInstance = false;
(function ($, wpMedia) {

  // Create the defaults once
  var pluginName = "wordpressAttachment",
    defaults = {
      attachmentIdFieldClass: 'field-attachment-id',
      attachmentIdFieldName: 'attachment',
      attachmentSize: 'thumbnail',
      updateContainerCallback: null,
      uploader: {
        title: 'Datei auswählen',
        button: {
          text: 'Datei auswählen',
          close: true
        },
        library: {
          type: '' // no type = everything
        },
        multiple: false
      }
    };

  // The actual plugin constructor
  function Plugin(element, options) {
    this.element = element;
		this.closing = false;

    this.options = $.extend({}, defaults, options);

    this._defaults = defaults;
    this._name = pluginName;

		this.init();
  }

  Plugin.prototype = {

    /**
     *
     */
    init: function () {
      var instance = this;
      instance.uploader = wp.media(instance.options.uploader);
			instance.uploader.on('open', function () {
				instance.open();
			});
			instance.uploader.on('select', function () {
				instance.select();
			});
			instance.uploader.on('close', function () {
				instance.closeAndSave();
			});

      $(this.element).on('click', '.remove-attachment', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).closest('.media-uploader-attachment').animate({opacity: 0}, 400, function(){
          $(this).animate({width: 0}, 400,function(){
            $(this).remove();
          });
        });
      });
      $(this.element).on('click', '.media-uploader-attachment, .button', function (e) {
        instance.uploader.open();
      });
    },

    /**
     *
     */
    open: function () {
      // get the selection object of the media uploader
      var selection = this.uploader.state().get('selection'),

      // map each attachment id value to a wp.media.attachment and fetch it
			attachments = $.map($('.' + this.options.attachmentIdFieldClass, this.element), function (element) {
				var attachment = wpMedia.attachment($(element).val());
				attachment.fetch();
				return attachment;
			});

      // add all fetched wp.media.attachment objects to the selection
      selection.add(attachments);
    },

    /**
     *
     */
    select: function () {
      var selection = this.uploader.state().get('selection').toJSON();
      this.update(selection);
			this.closeAndSave();
    },

    /**
     *
     * @param selection
     */
    update: function (selection) {
      var instance = this;
      if (typeof instance.options.updateContainerCallback == 'string') {
        var getFunction = new Function('return ' + instance.options.updateContainerCallback + ';');
        // get the function object and
        getFunction()(selection, instance);
      } else {
        var attachmentIdFieldName;
        if (typeof instance.options.post !== 'undefined') {
          attachmentIdFieldName = instance.options.post.ID + '_' + instance.options.key;
        }else{
          attachmentIdFieldName = instance.options.attachmentIdFieldName;
        }
        if (instance.options.uploader.multiple) {
          attachmentIdFieldName += '[]';
        }
        var mediaUploaderAttachments = $.map(selection, function(attachment){
          return instance.formatAttachment(attachment, attachmentIdFieldName, instance);
        });

        $('.media-uploader-attachments', instance.element).html(mediaUploaderAttachments.join(''));
      }
    },

		closeAndSave : function()
		{
			// Only close and save once
			if (!this.closing) {
				setTimeout(function() {
					// See if there is a save button and save
					var saveButton = jQuery('#save-action input[type=submit]');
					var publishButton = jQuery('#publishing-action input[type=submit]');
					if (saveButton.length == 1) {
						saveButton.trigger('click');
					} else if (publishButton.length == 1) {
						publishButton.trigger('click');
					}
				}, 1000);

				this.closing = true;
			}

		},

    formatAttachment: function (attachment, attachmentIdFieldName, instance) {

      var imageUrl = attachment.icon, size;
      if (typeof attachment.sizes !== 'undefined') {
        if(typeof attachment.sizes[instance.options.attachmentSize] !== 'undefined'){
          size = attachment.sizes[instance.options.attachmentSize];
        }else{
          size = attachment.sizes[Object.getOwnPropertyNames(attachment.sizes)[0]];
        }
        imageUrl = size.url;
      }
      var mediaContainer = $('<div class="' + attachment.type + '-media-container media-container"><img src="' + imageUrl + '" /></div>');
      if (typeof attachment.sizes !== 'undefined') {
        mediaContainer.css({
          padding: 100 * (size.height / size.width) + '% 0 0 0',
          width: size.width + 'px'
        });
      }else{
        mediaContainer.append($('<p><strong>'+ attachment.name +'</strong></p>'));
      }

      return $('<li class="media-uploader-attachment"></li>')
        .append($('<a href="#" class="remove-attachment"></a>'))
        .append(mediaContainer)
        .append($('<input type="hidden" class="' + instance.options.attachmentIdFieldClass + '" name="' + attachmentIdFieldName + '" value="' + attachment.id + '" />'))
        .wrap('<div>').parent().html();
    }
  }

  $.fn[pluginName] = function (options) {
    return this.each(function () {
      if (!$.data(this, "plugin_" + pluginName)) {
        $.data(this, "plugin_" + pluginName,
          new Plugin(this, options));
      }
    });
  };

})(jQuery, wp.media);