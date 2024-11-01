jQuery(function($){

	try {
		plupload_init();
	} catch (err) {
		console.log(err);
	}

	function plupload_init() {
	
		// create the uploader and pass the config from above
		var uploader = new plupload.Uploader(uploadziplion.plupload_config);

		// checks if browser supports drag and drop upload, makes some css adjustments if necessary
		uploader.bind('Init', function(up){
			var uploaddiv = $('#plupload-upload-ui');
			
			if(up.features.dragdrop){
				uploaddiv.addClass('drag-drop');
				$('#drag-drop-area')
				.bind('dragover.wp-uploader', function(){ uploaddiv.addClass('drag-over'); })
				.bind('dragleave.wp-uploader, drop.wp-uploader', function(){ uploaddiv.removeClass('drag-over'); });
				
			} else {
				uploaddiv.removeClass('drag-drop');
				$('#drag-drop-area').unbind('.wp-uploader');
			}
		});
					
		uploader.init();

		// a file was added in the queue
		uploader.bind('FilesAdded', function(up, files){
		
			plupload.each(files, function(file){

				if (! /\.zip$/.test(file.name)) {
					var accepted_file = false;
					for (var i = 0; i<ulp_accept_archivename.length; i++) {
						if (ulp_accept_archivename[i].test(file.name)) {
							var accepted_file = true;
						}
					}
					if (!accepted_file) {
						if (/\.zip$/i.test(file.name)) {
							jQuery('#ulp-message-modal-innards').html('<p><strong>'+file.name+"</strong></p> "+uploadziplion.notarchive2);
							jQuery('#ulp-message-modal').dialog('open');
						} else {
							alert(file.name+": "+uploadziplion.notarchive);
						}
						uploader.removeFile(file);
						return;
					}
				}
				
				// a file was added, you may want to update your DOM here...
				$('#filelist').append(
					'<div class="file" id="' + file.id + '"><b>' +
					file.name + '</b> (<span>' + plupload.formatSize(0) + '</span>/' + plupload.formatSize(file.size) + ') ' +
					'<div class="fileprogress"></div></div>');
			});
			
			var multipart_params = up.getOption('multipart_params');
			multipart_params.addrule = $('#addrule').is(':checked');
			up.setOption('multipart_params', multipart_params);
			
			up.refresh();
			up.start();
		});
			
		uploader.bind('UploadProgress', function(up, file) {
			$('#' + file.id + " .fileprogress").width(file.percent + "%");
			$('#' + file.id + " span").html(plupload.formatSize(parseInt(file.size * file.percent / 100)));
		});

		uploader.bind('Error', function(up, error) {
			alert(uploadziplion.uploaderr+' (code '+error.code+') : '+error.message+' ('+uploadziplion.makesure+')');
		});

		// a file was uploaded 
		uploader.bind('FileUploaded', function(up, file, response) {
			
			if (response.status == '200') {
				// this is your ajax response, update the DOM with it or something...
				try {
					resp = JSON.parse(response.response);
					if (resp.hasOwnProperty('result')) {
						if (!resp.result) {
							alert(resp.message);
							console.log(resp);
						} else {
							console.log(resp);
							alert(resp.message);
						}
					} else {
						alert('Unknown server response: '+response.response);
						console.log(resp);
					}
					
				} catch(err) {
					console.log(err);
					console.log(response);
					alert(uploadziplion.jsonnotunderstood);
				}

			} else {
				alert('Unknown server response status: '+response.code);
				console.log(response);
			}

		});
	}
	
});
