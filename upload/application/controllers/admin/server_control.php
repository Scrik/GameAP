<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * Game AdminPanel (АдминПанель)
 *
 * 
 *
 * @package		Game AdminPanel
 * @author		Nikita Kuznetsov (ET-NiK)
 * @copyright	Copyright (c) 2013, Nikita Kuznetsov (http://hldm.org)
 * @license		http://gameap.ru/license.html
 * @link		http://gameap.ru
 * @filesource	
 */
 
// ------------------------------------------------------------------------

/**
 * Управление сервером
 *
 * Страница управления сервером, отображение основной
 * информации о сервере	
 *
 * @package		Game AdminPanel
 * @category	Controllers
 * @category	Controllers
 * @author		Nikita Kuznetsov (ET-NiK)
 * @sinse		0.1
 */
class Server_control extends CI_Controller {
	
	//Template
	var $tpl_data = array();
	
	var $user_data = array();
	var $server_data = array();
	
	// Количество игроков на сервере
	var $players = 0;
	
	public function __construct()
    {
        parent::__construct();
		
		$this->load->database();
        $this->load->model('users');
        $check = $this->users->check_user();
        
        if($check){
			
			$this->load->model('servers');
			$this->lang->load('server_control');
			
			//Base Template
			$this->tpl_data['title'] 	= lang('server_control_title');
			$this->tpl_data['heading'] 	= lang('server_control_header');
			$this->tpl_data['content'] = '';
			$this->tpl_data['menu'] = $this->parser->parse('menu.html', $this->tpl_data, TRUE);
			$this->tpl_data['profile'] = $this->parser->parse('profile.html', $this->users->tpl_userdata(), TRUE);
        
        }else{
            redirect('auth');
        }
    }
    
	// Отображение информационного сообщения
    function _show_message($message = FALSE, $link = FALSE, $link_text = FALSE)
    {
        
        if (!$message) {
			$message = lang('error');
		}
		
        if (!$link) {
			$link = 'javascript:history.back()';
		}
		
		if (!$link_text) {
			$link_text = lang('back');
		}

        $local_tpl_data['message'] = $message;
        $local_tpl_data['link'] = $link;
        $local_tpl_data['back_link_txt'] = $link_text;
        $this->tpl_data['content'] = $this->parser->parse('info.html', $local_tpl_data, TRUE);
        $this->parser->parse('main.html', $this->tpl_data);
    }
    
    
    // Команды
    public function main($server_id = FALSE)
    {
        $this->load->driver('rcon');
        
        $this->load->helper('date');
        
        $this->load->helper('serverinfo');
		//$this->load->model('patterns');
		$this->load->model('valve_rcon');
        
        $error = 0;
        $error_desc = null;

        if($this->users->user_id){
            
            if(!$server_id) {
				$this->_show_message(lang('server_control_empty_server_id'));
				return FALSE;
			} else {
					$server_id = (int)$server_id;
			}
			
			/* Получение данных сервера и привилегий на сервер */
			$this->servers->server_data = $this->servers->get_server_data($server_id);
			$this->users->get_server_privileges($server_id);
					
			if(!$this->users->auth_servers_privileges['VIEW']) {
				$this->_show_message(lang('server_control_server_not_found'));
				return FALSE;
			}
					
			if(!$this->servers->server_data) {
				$this->_show_message(lang('server_control_server_not_found'));
				return FALSE;
			}

			if(!$error_desc){
				$rcon_connect = FALSE;
				
				if ($this->servers->server_status($this->servers->server_data['server_ip'], $this->servers->server_data['query_port'])) {
					$this->servers->server_data['server_status'] = 1;
					
					$this->rcon->set_variables(
												$this->servers->server_data['server_ip'],
												$this->servers->server_data['rcon_port'],
												$this->servers->server_data['rcon'], 
												$this->servers->servers->server_data['engine']
					);
					
					$rcon_connect = $this->rcon->connect();
					
				} else {
					$this->servers->server_data['server_status'] = 0;
				}
				
				
				if($this->servers->server_data['server_status']) {

					// Отправка команды
					$rcon_string = $this->rcon->command("status");
					
					$local_tpl_data['users_list'] = FALSE;
					
					if($rcon_string){
							$local_tpl_data['users_list'] = $this->rcon->get_players($rcon_string, $this->servers->server_data['engine']);
					}
					
					if(!$local_tpl_data['users_list']){
						$this->tpl_data['content'] .= lang('server_control_empty_player_list');
					}else{
						$this->players = 1;
					}
					
					/* 
					 * == Список карт ==
					/* Если FTP настроен или сервер локальный, то получаем список карт
					 * напрямую из списка сервера
					 * иначе берем через ркон
					*/
					if(!$this->servers->server_data['ds_id'] OR $this->servers->server_data['ftp_host']){
						$local_tpl_data['maps_list'] = $this->servers->get_server_maps();
					}else{
						$this->tpl_data['content'] .= '<p>' . lang('server_control_no_ftp') . '</p>';
						$local_tpl_data['maps_list'] = $this->rcon->get_maps();
					}
					
					/*
					 * FAST RCON
					 * 
					 * Декодирование json списка с командами
					*/
					
					$frcon_list = json_decode($this->servers->server_data['fast_rcon'], TRUE);
					if($frcon_list) {
						$i = -1;
						$local_tpl_data['frcon_list'] = $frcon_list;
						foreach($frcon_list as $array) {
							$i ++;
							$local_tpl_data['frcon_list'][$i]['id_fr'] = $i;
						}
					} else {
						$local_tpl_data['frcon_list'] = array();
					}
					
					
				} else {
					// Ошибка соединения с сервером
					$this->tpl_data['content'] .= lang('server_control_server_down');
				}
				
				/* Получение последних действий с сервером
				 *  
				 * количество получаемых логов = 50
				 * количество отображаемых логов = 10
				 * 
				 * Некоторые из получаемых логов могут не относиться к серверам, из-за этого
				 * таблица может быть пустой
				 * 
				*/
				$where = array('server_id' => $server_id);
				$server_plogs = $this->panel_log->get_log($where, 50); // Логи сервера в админпанели
				
				$local_tpl_data['log_list'] = array();
				
				$log_num = 0;
				$i = 0;
				$count_i = count($server_plogs);
				while($i < $count_i){
					
					if($log_num == 10) {
						break;
					}
					
					$local_tpl_data['log_list'][$i]['log_id'] = $server_plogs[$i]['id'];
					$local_tpl_data['log_list'][$i]['log_date'] = unix_to_human($server_plogs[$i]['date'], TRUE, 'eu');
					$local_tpl_data['log_list'][$i]['log_server_id'] = $server_plogs[$i]['server_id'];
					$local_tpl_data['log_list'][$i]['log_user_name'] = $server_plogs[$i]['user_name'];
					$local_tpl_data['log_list'][$i]['log_command'] = $server_plogs[$i]['command'];
					
					
					/* Код действия на понятный язык */
					switch($server_plogs[$i]['type']){
						case 'server_rcon':
							$local_tpl_data['log_list'][$i]['log_type'] = lang('server_control_rcon_send');
							$log_num ++;
							break;
							
						case 'server_command':
							$local_tpl_data['log_list'][$i]['log_type'] = lang('server_control_command');
							$log_num ++;
							break;
							
						case 'server_update':
							$local_tpl_data['log_list'][$i]['log_type'] = lang('server_control_update');
							$log_num ++;
							break;
						case 'server_task':
							$local_tpl_data['log_list'][$i]['log_type'] = lang('server_control_srv_task');
							$log_num ++;
							break;
							
						case 'server_settings':
							$local_tpl_data['log_list'][$i]['log_type'] = lang('server_control_settings');
							$log_num ++;
							break;
							
						case 'server_files':
							$local_tpl_data['log_list'][$i]['log_type'] = lang('server_control_file_operation');
							$log_num ++;
							break;
							
						default:
							// Тип лога неизвестен, удаляем его из списка (не из базы)
							unset($local_tpl_data['log_list'][$i]);
							break;
					}
					
					$i ++;
				}
				
				/* Крон задания */
				$local_tpl_data['task_list'] = array();
				
				if($this->users->auth_servers_privileges['TASK_MANAGE']) {
					
					$where = array('server_id' => $server_id);
					$query = $this->db->order_by('date_perform', 'asc');
					$query = $this->db->get_where('cron', $where);
					
					if($query->num_rows > 0) {
						$task_list = $query->result_array();
					} else {
						$task_list = array();
					}
					
					$i = 0;
					$count_i = count($task_list);
					while($i < $count_i) {

						switch($task_list[$i]['code']) {
							case 'server_start':
								$local_tpl_data['task_list'][$i]['task_action'] = lang('server_control_start');
								break;
							case 'server_stop':
								$local_tpl_data['task_list'][$i]['task_action'] = lang('server_control_stop');
								break;
							case 'server_restart':
								$local_tpl_data['task_list'][$i]['task_action'] = lang('server_control_restart');
								break;
							case 'server_update':
								$local_tpl_data['task_list'][$i]['task_action'] = lang('server_control_update');
								break;
							case 'server_rcon':
								$local_tpl_data['task_list'][$i]['task_action'] = lang('server_control_rcon_send');
								break;
							default:
								continue;
								break;
						}
						
						$local_tpl_data['task_list'][$i]['task_id'] = $task_list[$i]['id'];
						$local_tpl_data['task_list'][$i]['task_name'] = $task_list[$i]['name'];
						$local_tpl_data['task_list'][$i]['task_date'] = unix_to_human($task_list[$i]['date_perform'], TRUE, 'eu');

						$i ++;
					
					}
					
				}
				
				$local_tpl_data['server_id'] = $server_id;
				$local_tpl_data['server_name'] = $this->servers->server_data['name'];
				$this->tpl_data['heading'] = lang('server_control_header') . ' "' . $this->servers->server_data['name'] . '"';
				
				if(file_exists('application/viewsserver_control/' . $this->servers->server_data['game'] . '.html')){
					$this->tpl_data['content'] .= $this->parser->parse('server_control/' . $this->servers->server_data['game'] . '.html', $local_tpl_data, TRUE);
				}else{
					$this->tpl_data['content'] .= $this->parser->parse('server_control/default.html', $local_tpl_data, TRUE);
				}
				
			}else{
				$this->tpl_data['content'] .= '<strong>' . lang('server_control_errors_found') . ' :</strong><br />' . $error_desc;
			}
        }

        $this->parser->parse('main.html', $this->tpl_data);
    }
    
    //-----------------------------------------------------------
	
	/**
     * Добавление нового задания для сервера
     * 
     * @param int - id сервера
     *
    */
    function add_task($server_id)
    {
		$this->load->library('form_validation');
		$this->load->helper('date');
		
		$local_tpl_data = array();
		
		if(!$server_id) {
				$this->_show_message(lang('server_control_empty_server_id'));
				return FALSE;
		} else {
				$server_id = (int)$server_id;
		}
		
		/* Получение данных сервера и привилегий на сервер */
		$this->servers->server_data = $this->servers->get_server_data($server_id);
		$this->users->get_server_privileges($server_id);
		
		/* Проверочки */
		if(!$this->users->auth_servers_privileges['VIEW']) {
			$this->_show_message(lang('server_control_server_not_found'));
			return FALSE;
		} elseif(!$this->servers->server_data) {
			$this->_show_message(lang('server_control_server_not_found'));
			return FALSE;
		} elseif(!$this->users->auth_servers_privileges['TASK_MANAGE']) {
			$this->_show_message(lang('server_control_no_task_privileges'));
			return FALSE;
		}
		
		/* Правила для формы */
		$this->form_validation->set_rules('name', 'имя', 'trim|required|max_length[64]|xss_clean');
		$this->form_validation->set_rules('code', 'команда', 'trim|required|max_length[32]|xss_clean');
		$this->form_validation->set_rules('command', 'параметры команды', 'trim|max_length[128]|xss_clean');
		
		$this->form_validation->set_rules('date_perform', 'дата выполнения', 'trim|required|max_length[19]|xss_clean');
		$this->form_validation->set_rules('time_add', 'период повтора', 'trim|required|integer|max_length[16]|xss_clean');
		
		$local_tpl_data['server_id'] = $server_id;
		
		if($this->form_validation->run() == FALSE) {
			$this->tpl_data['content'] .= $this->parser->parse('servers/task_add.html', $local_tpl_data, TRUE);
		} else {
			
			$sql_data['server_id'] = $server_id;
			
			$sql_data['name'] = $this->input->post('name');
			$sql_data['code'] = $this->input->post('code');
			$sql_data['command'] = $this->input->post('command');

			
			if(!$sql_data['date_perform'] = human_to_unix($this->input->post('date_perform'))) {
				$this->_show_message(lang('server_control_date_unavailable'), 'javascript:history.back()');
				return FALSE;
			}
			
			$sql_data['time_add'] = $this->input->post('time_add');
			
			$this->db->insert('cron', $sql_data);
			
			// Сохраняем логи
			$log_data['type'] = 'server_task';
			$log_data['command'] = 'add_task';
			$log_data['user_name'] = $this->users->user_login;
			$log_data['server_id'] = $server_id;
			$log_data['msg'] = 'Add new task';
			$log_data['log_data'] = 'Name: ' . $sql_data['name'];
			$this->panel_log->save_log($log_data);
			
			$this->_show_message(lang('server_control_new_task_success'), site_url('admin/server_control/main/' . $server_id), 'Далее');
			return TRUE;
			
		}
		
		$this->parser->parse('main.html', $this->tpl_data);
	}
	
	//-----------------------------------------------------------
	
	/**
     * Добавление нового задания для сервера
     * 
     * @param int - id сервера
     *
    */
    function delete_task($task_id, $confirm = FALSE)
    {
		if(!$task_id) {
				$this->_show_message(lang('server_control_empty_task_id'));
				return FALSE;
		} else {
				$task_id = (int)$task_id;
		}
		
		$this->load->helper('date');
		
		$local_tpl_data = array();
		
		// Получение информации об удаляемом задании
		$where = array('id' => $task_id);
		$query = $this->db->get_where('cron', $where, 1);
		
		if($query->num_rows > 0){
			$task_list = $query->result_array();
		} else {
			$this->_show_message(lang('server_control_task_not_found'));
			return FALSE;
		}
		
		/* Задание может не относится к серверу, такие нам не нужны */
		if(!$task_list[0]['server_id']) {
			$this->_show_message(lang('server_control_task_not_found'));
			return FALSE;
		}
		
		/* Получение данных сервера и привилегий на сервер */
		$this->servers->server_data = $this->servers->get_server_data($task_list[0]['server_id']);
		$this->users->get_server_privileges($task_list[0]['server_id']);
		
		/* Проверочки */
		if(!$this->users->auth_servers_privileges['VIEW']) {
			$this->_show_message(lang('server_control_task_not_found'));
			return FALSE;
		} elseif(!$this->servers->server_data) {
			$this->_show_message(lang('server_control_task_not_found'));
			return FALSE;
		} elseif(!$this->users->auth_servers_privileges['TASK_MANAGE']) {
			$this->_show_message(lang('server_control_no_task_privileges'));
			return FALSE;
		}

		if($confirm != 'confirm') {
			
			/* Пользователь не подвердил намерения */
			$confirm_tpl['message'] = lang('server_control_task_delete_confirm');
			$confirm_tpl['confirmed_url'] = site_url('admin/server_control/delete_task/' . $task_id . '/confirm');
			$this->tpl_data['content'] .= $this->parser->parse('confirm.html', $confirm_tpl, TRUE);

		} else {
			$this->db->where('id', $task_id);
			$this->db->delete('cron');
			
			// Сохраняем логи
			$log_data['type'] = 'server_task';
			$log_data['command'] = 'delete_task';
			$log_data['user_name'] = $this->users->user_login;
			$log_data['server_id'] = $task_list[0]['server_id'];
			$log_data['msg'] = 'Delete task';
			$log_data['log_data'] = '';
			$this->panel_log->save_log($log_data);
			
			$this->_show_message(lang('server_control_task_saved'), site_url('/admin/server_control/main/' . $task_list[0]['server_id']), 'Далее');
			return TRUE;
		}
		
		$this->parser->parse('main.html', $this->tpl_data);
	}
	
	//-----------------------------------------------------------
	
	/**
     * Добавление нового задания для сервера
     * 
     * @param int - id сервера
     *
    */
    function edit_task($task_id)
    {
		
		if(!$task_id) {
			$this->_show_message(lang('server_control_empty_task_id'));
			return FALSE;
		} else {
			$task_id = (int)$task_id;
		}
		
		$this->load->library('form_validation');
		$this->load->helper('form');
		$this->load->helper('date');
		
		$local_tpl_data = array();
		
		// Получение информации о редактируемом задании
		$where = array('id' => $task_id);
		$query = $this->db->get_where('cron', $where, 1);
		
		if($query->num_rows > 0){
			$task_list = $query->result_array();
		} else {
			$this->_show_message(lang('server_control_task_not_found'));
			return FALSE;
		}
		
		/* Задание может не относится к серверу, такие нам не нужны */
		if(!$task_list[0]['server_id']) {
			$this->_show_message(lang('server_control_task_not_found'));
			return FALSE;
		}
		
		/* Получение данных сервера и привилегий на сервер */
		$this->servers->server_data = $this->servers->get_server_data($task_list[0]['server_id']);
		$this->users->get_server_privileges($task_list[0]['server_id']);
		
		/* Проверочки */
		if(!$this->users->auth_servers_privileges['VIEW']) {
			$this->_show_message(lang('server_control_task_not_found'));
			return FALSE;
		} elseif(!$this->servers->server_data) {
			$this->_show_message(lang('server_control_task_not_found'));
			return FALSE;
		} elseif(!$this->users->auth_servers_privileges['TASK_MANAGE']) {
			$this->_show_message(lang('server_control_no_task_privileges'));
			return FALSE;
		}
		
		/* Правила для формы */
		$this->form_validation->set_rules('name', 'имя', 'trim|required|max_length[64]|xss_clean');
		$this->form_validation->set_rules('code', 'команда', 'trim|required|max_length[32]|xss_clean');
		$this->form_validation->set_rules('command', 'параметры команды', 'trim|max_length[128]|xss_clean');
		
		$this->form_validation->set_rules('date_perform', 'дата выполнения', 'trim|required|max_length[19]|xss_clean');
		$this->form_validation->set_rules('time_add', 'период повтора', 'trim|required|integer|max_length[16]|xss_clean');
		
		if($this->form_validation->run() == FALSE) {
			
			$options['code'] = array(
				'server_start' => 	lang('server_control_start'),
				'server_stop' => 	lang('server_control_stop'),
				'server_restart' =>	lang('server_control_restart'),
				'server_update' =>	lang('server_control_update'),
				'server_rcon' =>	lang('server_control_rcon_send')
			);
			
			$options['time_add'] = array(
				'0' => 		 lang('server_control_never'),
				'86400' =>	 lang('server_control_day'),
				'172800' =>	 lang('server_control_two_day'),
				'604800' =>	 lang('server_control_week'),
				'2592000' => lang('server_control_month'),
			);
			
			/* Создание форм */
			$local_tpl_data['input_code'] = form_dropdown('code', $options['code'], $task_list[0]['code']);
			$local_tpl_data['input_time_add'] = form_dropdown('time_add', $options['time_add'], $task_list[0]['time_add']);

			$local_tpl_data['code'] = $task_list[0]['code'];	
			$local_tpl_data['command'] = $task_list[0]['command'];	
			$local_tpl_data['task_id'] = $task_list[0]['id'];
			$local_tpl_data['name'] = $task_list[0]['name'];
			$local_tpl_data['date_perform'] = unix_to_human($task_list[0]['date_perform'], TRUE, 'eu');
			
			$this->tpl_data['content'] .= $this->parser->parse('servers/task_edit.html', $local_tpl_data, TRUE);
		} else {
			$sql_data['name'] = $this->input->post('name');
			$sql_data['code'] = $this->input->post('code');
			$sql_data['command'] = $this->input->post('command');
			$sql_data['time_add'] = $this->input->post('time_add');
			
			if(!$sql_data['date_perform'] = human_to_unix($this->input->post('date_perform'))) {
				$this->_show_message(lang('server_control_date_unavailable'), 'javascript:history.back()');
				return FALSE;
			}
			
			// Сбрасываем, если заданание уже выполнялось
			$sql_data['date_performed'] = '';
			
			//$sql_data['time_add'] = $this->input->post('time_add');
			
			$this->db->where('id', $task_id);
			$this->db->update('cron', $sql_data);
			
			// Сохраняем логи
			$log_data['type'] = 'server_task';
			$log_data['command'] = 'edit_task';
			$log_data['user_name'] = $this->users->user_login;
			$log_data['server_id'] = $task_list[0]['server_id'];
			$log_data['msg'] = 'Edit task';
			$log_data['log_data'] = 'Name: ' . $sql_data['name'];
			$this->panel_log->save_log($log_data);
			
			$this->_show_message(lang('server_control_task_saved'), site_url('/admin/server_control/main/' . $task_list[0]['server_id']), 'Далее');
			return TRUE;
		}
		
		$this->parser->parse('main.html', $this->tpl_data);
	}
	
	
}

/* End of file server_control.php */
/* Location: ./application/controllers/admin/server_control.php */
