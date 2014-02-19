unless File.exists?("#{shred_path}/system/wordpress")
  run "wp core download --locale=ja --path=#{shred_path}/system/wordpress"
end
