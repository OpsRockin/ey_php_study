unless File.exists?("#{config.shared_path}/system/wordpress")
  run "wp core download --locale=ja --path=#{config.shared_path}/system/wordpress"
end
