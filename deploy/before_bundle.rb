unless File.exists?("#{shared_path}/system/wordpress")
  run "wp core download --locale=ja --path=#{shared_path}/system/wordpress"
end
