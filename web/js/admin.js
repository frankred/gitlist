jQuery(document).ready(function($) {

	function error(message, target) {
		$(target).html('<div class="alert alert-error"><a class="close" data-dismiss="alert">×</a>' + message + '</div>');
	}

	function success(message, target) {
		$(target).html('<div class="alert alert-success"><a class="close" data-dismiss="alert">×</a>' + message + '</div>');
	}

	// Tabs
	$('#adminTabs a').click(function(e) {
		e.preventDefault();
		var url = $(this).attr("data-url");
		var pane = $(this);
		pane.tab('show');
	});

	// load first tab content
	$('#paths').load($('.active a').attr("data-url"), function(result) {
		$('.active a').tab('show');
	});

	// Change properties in config.ini
	var timeout;

	// Simple properties like booleans and strings
	$(".singleProperty").on('input change', function() {
		clearTimeout(timeout);
		var self = this;
		timeout = setTimeout(function() {
			$.ajax({
				url : 'edit/' + $(self).attr('name'),
				type : 'POST',
				data : {
					value : $(self).attr('value')
				},
				dataType : 'json',
				success : function(data, textStatus, jqXHR) {
					if (data.type == 'succeed') {
						success(data.message, "div.messages");
					} else {
						error(data.message, "div.messages");
					}
				}
			});
		}, 200);
	});

	// Add a new account
	$("#add_account").on('click', function() {
		var name = $("#add_account_name").val();
		var password = $("#add_account_password").val();
		var role = $("#add_account_role").val();
		var attribute;

		if (name == '') {
			error('Field <strong>name</strong> can not be empty!', "div.add_account_modal_messages");
			return false;
		}

		if (password == '') {
			error('Field <strong>password</strong> can not be empty!', "div.add_account_modal_messages");
			return false;
		}

		if (role == 'ROLE_ADMIN') {
			attribute = 'admins';
		} else if (role == 'ROLE_USER') {
			attribute = 'users';
		}

		$.ajax({
			url : 'add_account/' + attribute,
			type : 'POST',
			data : {
				name : name,
				password : password
			},
			dataType : 'json',
			success : function(data, textStatus, jqXHR) {
				if (data.type == 'succeed') {
					success(data.message, "div.messages");
					$("#add_account_modal").modal("hide");
					$("div.add_account_modal_messages").hide();
					$("#add_account_name").val('');
					$("#add_account_password").val('');

					var icon;

					if (role == 'ROLE_ADMIN') {
						icon = 'icon-wrench';
					} else if (role == 'ROLE_USER') {
						icon = 'icon-user';
					}
					$("#accounts_table").append('<tr>' + '<td><i class="' + icon + ' icon-spaced"></i>' + name + '</td>' + '<td>' + role + '</td>' + '<td><a class="btn remove-account" href="#" data-name="' + name + '">remove</a></td>' + '</tr>');
				} else {
					error(data.message, "div.add_account_modal_messages");
				}
			}
		});
		return false;
	});

	// Remove account
	$(document).on('click', '.remove-account', function() {
		var name = $(this).attr('data-name');
		var self = $(this);
		$.ajax({
			url : 'remove_account/' + name,
			type : 'GET',
			dataType : 'json',
			success : function(data, textStatus, jqXHR) {
				if (data.type == 'succeed') {
					success(data.message, "div.messages");
					self.closest('tr').fadeOut(300, function() {
						$(this).remove();
					});
				} else {
					error(data.message, "div.messages");
				}
			}
		});

	});

	// Add repository
	$("#add_repo").on('click', function() {
		var repository = $("#add_repo_path").val();

		$.ajax({
			url : 'add_repo',
			type : 'POST',
			data : {
				newrepopath : repository
			},
			dataType : 'json',
			success : function(data, textStatus, jqXHR) {

				if (data.type == 'succeed') {
					success(data.message, "div.messages");
					$("#add_repo_modal").modal("hide");
					$("div.add_repo_modal_messages").hide();
					$("#add_repo_path").val('');

					$("#repos_table").append('<tr>' + '<td><i class="icon-folder-open icon-spaced"></i><a href="' + repository + '">' + repository + '</a></td>' + '<td><a class="btn remove-repo" href="#" data-repofolder="' + repository + '">remove</a></td>' + '</tr>');
				} else {
					error(data.message, "div.add_repo_modal_messages");
				}
			}
		});
		return false;
	});

	// Remove repository
	$(document).on('click', '.remove-repo', function() {
		var repofolder = $(this).attr('data-repofolder');
		var self = $(this);

		$.ajax({
			url : 'remove_repo',
			type : 'POST',
			dataType : 'json',
			data : {
				repo : repofolder
			},
			success : function(data, textStatus, jqXHR) {
				if (data.type == 'succeed') {
					success(data.message, "div.messages");
					self.closest('tr').fadeOut(300, function() {
						$(this).remove();
					});
				} else {
					error(data.message, "div.messages");
				}
			}
		});
		return false;
	});

});

