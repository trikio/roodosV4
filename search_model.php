<?php
use Foolz\SphinxQL\SphinxQL;
use Foolz\SphinxQL\Helper;
use Foolz\SphinxQL\Drivers\Mysqli\Connection;

class Search_model {

	public $database;
	
    public function __construct()
	{
		$this->database = MysqliDb::getInstance();
    }

	public function car_search($search_index,$options)
	{

		if($options['page'] == ''){
			$options['page'] = 1;
		}

		$columns = array('title','details','make_name','model_name','city_name','state_name','year_string','fuel_name','transmission_name', 'body_name');

		$sphinxconn = new Connection();
        $sphinxconn->setParams(array('host' => '127.0.0.1', 'port' => 9306));
 
		$query = (new SphinxQL($sphinxconn))

			->select('*')
			->from($search_index);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'));

			$query = $this->car_common_query($query,$options);

			$query->limit(((int)$options['page']-1)*get_config()['results_per_page'], get_config()['results_per_page']);

			if($options['order'] == 'priceasc'){
				$query->orderBy('price', 'ASC');
			}
			if($options['order'] == 'pricedesc'){
				$query->orderBy('price', 'DESC');
			}
			if($options['order'] == 'yearasc'){
				$query->orderBy('year', 'ASC');
			}
			if($options['order'] == 'yeardesc'){
				$query->orderBy('year', 'DESC');
			}
			if($options['order'] == 'kmasc'){
				$query->orderBy('km', 'ASC');
			}
			if($options['order'] == 'kmdesc'){
				$query->orderBy('km', 'DESC');
			}

			$query->option('max_matches', 500000);

			$query = $query->enqueue((new Helper($sphinxconn))->showMeta());
			$query = $query->enqueue();


			//MAKE
			$query->select('id_car_make, make_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->car_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_car_make')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

			//MODEL
			$query->select('id_car_model, model_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->car_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_car_model')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

			//STATE
			$query->select('id_state, state_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->car_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_state')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

			//CITY
			$query->select('id_city, city_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->car_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_city')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

			//FUEL
			$query->select('id_fuel, fuel_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->car_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_fuel')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

			//TRANSMISSION
			$query->select('id_transmission, transmission_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->car_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_transmission')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

			//BODY
			$query->select('id_body, body_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->car_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_body')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

		

		$sphinxRes = $query->executeBatch();
		
		//resultados
		$result['items'] = $sphinxRes->getNext()->fetchAllAssoc();
		//total de resultados
		$result['info'] = $sphinxRes->getNext()->fetchAllAssoc();
		
		//make
		$result['make'] = $sphinxRes->getNext()->fetchAllAssoc();
		//model
		$result['model'] = $sphinxRes->getNext()->fetchAllAssoc();
		//state
		$result['state'] = $sphinxRes->getNext()->fetchAllAssoc();
		//city
		$result['city'] = $sphinxRes->getNext()->fetchAllAssoc();
		//fuel
		$result['fuel'] = $sphinxRes->getNext()->fetchAllAssoc();
		//transmission
		$result['transmission'] = $sphinxRes->getNext()->fetchAllAssoc();
		//body
		$result['body'] = $sphinxRes->getNext()->fetchAllAssoc();

		return($result);

	}

	public function car_common_query($query,$options)
	{
		if($options['make'] != ''){
			$query->where('id_car_make', (int)$options['make']);
		}
		if($options['model'] != ''){
			$query->where('id_car_model', (int)$options['model']);
		}
		if($options['state'] != ''){
			$query->where('id_state', (int)$options['state']);
		}
		if($options['city'] != ''){
			$query->where('id_city', (int)$options['city']);
		}
		if($options['fuel'] != ''){
			$query->where('id_fuel', (int)$options['fuel']);
		}
		if($options['transmission'] != ''){
			$query->where('id_transmission', (int)$options['transmission']);
		}
		if($options['body'] != ''){
			$query->where('id_body', (int)$options['body']);
		}

		if(($options['min_price'] != "")&&($options['max_price'] != "")){
			$query->where('price', 'BETWEEN', array((int)$options['min_price'], (int)$options['max_price']));
		}
		if(($options['min_price'] != "")&&($options['max_price'] == "")){
			$query->where('price', '>=', (int)$options['min_price']);
		}
		if(($options['min_price'] == "")&&($options['max_price'] != "")){
			$query->where('price', '<=', (int)$options['max_price']);
		}

		if(($options['min_year'] != "")&&($options['max_year'] != "")){
			$query->where('year', 'BETWEEN', array((int)$options['min_year'], (int)$options['max_year']));
		}
		if(($options['min_year'] != "")&&($options['max_year'] == "")){
			$query->where('year', '>=', (int)$options['min_year']);
		}
		if(($options['min_year'] == "")&&($options['max_year'] != "")){
			$query->where('year', '<=', (int)$options['max_year']);
		}

		if(($options['min_km'] != "")&&($options['max_km'] != "")){
			$query->where('km', 'BETWEEN', array((int)$options['min_km'], (int)$options['max_km']));
		}
		if(($options['min_km'] != "")&&($options['max_km'] == "")){
			$query->where('km', '>=', (int)$options['min_km']);
		}
		if(($options['min_km'] == "")&&($options['max_km'] != "")){
			$query->where('km', '<=', (int)$options['max_km']);
		}

		return($query);
	}

	public function house_search($search_index,$options)
	{

		// sql_attr_string	= url
		// sql_attr_string	= image
		// sql_attr_uint	= id_house_operation
		// sql_attr_uint	= id_house_type
		// sql_attr_uint	= id_state
		// sql_attr_uint	= id_city
		// sql_attr_uint	= price
		// sql_attr_uint	= rooms
		// sql_attr_uint	= bath
		// sql_attr_uint	= size

		if($options['page'] == ''){
			$options['page'] = 1;
		}

		$columns = array('title','details','house_operation_name','house_type_name','city_name','state_name');

		$sphinxconn = new Connection();
        $sphinxconn->setParams(array('host' => '127.0.0.1', 'port' => 9306));
 
		$query = (new SphinxQL($sphinxconn))

			->select('*')
			->from($search_index);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'));

			$query = $this->house_common_query($query,$options);

			$query->limit(((int)$options['page']-1)*get_config()['results_per_page'], get_config()['results_per_page']);

			if($options['order'] == 'priceasc'){
				$query->orderBy('price', 'ASC');
			}
			if($options['order'] == 'pricedesc'){
				$query->orderBy('price', 'DESC');
			}
			if($options['order'] == 'sizeasc'){
				$query->orderBy('size', 'ASC');
			}
			if($options['order'] == 'sizedesc'){
				$query->orderBy('size', 'DESC');
			}

			$query->option('max_matches', 500000);

			$query = $query->enqueue((new Helper($sphinxconn))->showMeta());
			$query = $query->enqueue();
		
				
			//OPERATION
			$query->select('id_house_operation, house_operation_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->house_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_house_operation')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	
			
			//TYPE
			$query->select('id_house_type, house_type_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->house_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_house_type')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

			//STATE
			$query->select('id_state, state_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->house_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_state')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

			//CITY
			$query->select('id_city, city_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->house_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_city')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	
			

		$sphinxRes = $query->executeBatch();
		
		//resultados
		$result['items'] = $sphinxRes->getNext()->fetchAllAssoc();
		//total de resultados
		$result['info'] = $sphinxRes->getNext()->fetchAllAssoc();

		//make
		$result['operation'] = $sphinxRes->getNext()->fetchAllAssoc();
		//model
		$result['type'] = $sphinxRes->getNext()->fetchAllAssoc();
		//state
		$result['state'] = $sphinxRes->getNext()->fetchAllAssoc();
		//city
		$result['city'] = $sphinxRes->getNext()->fetchAllAssoc();
		

		return($result);

	}

	public function house_common_query($query,$options)
	{
		if($options['operation'] != ''){
			$query->where('id_house_operation', (int)$options['operation']);
		}
		if($options['type'] != ''){
			$query->where('id_house_type', (int)$options['type']);
		}
		if($options['state'] != ''){
			$query->where('id_state', (int)$options['state']);
		}
		if($options['city'] != ''){
			$query->where('id_city', (int)$options['city']);
		}


		if(($options['min_price'] != "")&&($options['max_price'] != "")){
			$query->where('price', 'BETWEEN', array((int)$options['min_price'], (int)$options['max_price']));
		}
		if(($options['min_price'] != "")&&($options['max_price'] == "")){
			$query->where('price', '>=', (int)$options['min_price']);
		}
		if(($options['min_price'] == "")&&($options['max_price'] != "")){
			$query->where('price', '<=', (int)$options['max_price']);
		}

		if($options['rooms'] != ''){
			$query->where('rooms', '>=', (int)$options['rooms']);
		}
		if($options['bath'] != ''){
			$query->where('bath', '>=', (int)$options['bath']);
		}

		if(($options['min_size'] != "")&&($options['max_size'] != "")){
			$query->where('size', 'BETWEEN', array((int)$options['min_size'], (int)$options['max_size']));
		}
		if(($options['min_size'] != "")&&($options['max_size'] == "")){
			$query->where('size', '>=', (int)$options['min_size']);
		}
		if(($options['min_size'] == "")&&($options['max_size'] != "")){
			$query->where('size', '<=', (int)$options['max_size']);
		}

		return($query);
	}

	public function job_search($search_index,$options)
	{
		// sql_field_string	= title
		// sql_field_string	= details
		// sql_field_string	= job_category_name
		// sql_field_string	= city_name
		// sql_field_string	= state_name

		// sql_attr_string	= url
		// sql_attr_uint	= id_job_category
		// sql_attr_uint	= id_state
		// sql_attr_uint	= id_city

		if($options['page'] == ''){
			$options['page'] = 1;
		}

		$columns = array('title','details','job_category_name','city_name','state_name');

		$sphinxconn = new Connection();
        $sphinxconn->setParams(array('host' => '127.0.0.1', 'port' => 9306));
 
		$query = (new SphinxQL($sphinxconn))

			->select('*')
			->from($search_index);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'));

			$query = $this->job_common_query($query,$options);

			$query->limit(((int)$options['page']-1)*get_config()['results_per_page'], get_config()['results_per_page']);

			$query->option('max_matches', 500000);
			
			$query = $query->enqueue((new Helper($sphinxconn))->showMeta());
			$query = $query->enqueue();

			//OPERATION
			$query->select('id_job_category, job_category_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->job_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_job_category')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	
			

			//STATE
			$query->select('id_state, state_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->job_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_state')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	

			//CITY
			$query->select('id_city, city_name as name, COUNT(*) as total')
			->from($search_index);

			$query = $this->job_common_query($query,$options);

			$query->match($columns, SphinxQL::expr('"'.$options['q'].'"/1'))
			->groupBy('id_city')
			->orderBy('total', 'DESC')
			->limit(0, 50);
			$query = $query->enqueue();	


		$sphinxRes = $query->executeBatch();
		
		//resultados
		$result['items'] = $sphinxRes->getNext()->fetchAllAssoc();
		//total de resultados
		$result['info'] = $sphinxRes->getNext()->fetchAllAssoc();
		//make
		$result['category'] = $sphinxRes->getNext()->fetchAllAssoc();
		//state
		$result['state'] = $sphinxRes->getNext()->fetchAllAssoc();
		//city
		$result['city'] = $sphinxRes->getNext()->fetchAllAssoc();


		return($result);

	}

	public function job_common_query($query,$options)
	{
		if($options['category'] != ''){
			$query->where('id_job_category', (int)$options['category']);
		}
		if($options['state'] != ''){
			$query->where('id_state', (int)$options['state']);
		}
		if($options['city'] != ''){
			$query->where('id_city', (int)$options['city']);
		}

		return($query);
	}

}
